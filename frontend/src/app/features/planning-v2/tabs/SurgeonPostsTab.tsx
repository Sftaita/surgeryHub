import * as React from "react";
import {
  Alert, Box, Button, CircularProgress, IconButton, InputAdornment, Menu, MenuItem, Stack, TextField, Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import SearchOutlinedIcon from "@mui/icons-material/SearchOutlined";
import ViewModuleOutlinedIcon from "@mui/icons-material/ViewModuleOutlined";
import ViewListOutlinedIcon from "@mui/icons-material/ViewListOutlined";
import ViewSidebarOutlinedIcon from "@mui/icons-material/ViewSidebarOutlined";
import MoreHorizIcon from "@mui/icons-material/MoreHoriz";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { fetchSites } from "../../sites/api/sites.api";
import { getSurgeons } from "../../manager-surgeons/api/surgeons.api";
import { getInstrumentists } from "../../manager-instrumentists/api/instrumentists.api";
import {
  getSurgeonPosts, createSurgeonPost, updateSurgeonPost,
  deactivateSurgeonPost, reactivateSurgeonPost, extractErrorV2,
} from "../api/planningV2.api";
import type { SurgeonSchedulePostV2, SurgeonPostInput } from "../api/planningV2.types";
import { useToast } from "../../../ui/toast/useToast";
import { PostCard } from "../components/PostCard";
import { PostFormDialog } from "../components/PostFormDialog";
import type { SearchableOption } from "../components/SearchableSelect";
import { ExceptionsSheet } from "../components/ExceptionsSheet";
import { EndingSoonAlertCard } from "../components/EndingSoonAlertCard";
import { isEndingSoon, findEndingSoonPosts } from "../api/endingSoon";
import { avatarColorFor, initialsFor } from "../../../ui/avatar/avatarColor";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

type Layout = "split" | "cards" | "rows";

interface SurgeonGroup {
  id: number;
  label: string;
  posts: SurgeonSchedulePostV2[];
}

export function SurgeonPostsTab() {
  const toast = useToast();
  const qc = useQueryClient();

  const [layout, setLayout] = React.useState<Layout>("split");
  const [selectedSurgeonId, setSelectedSurgeonId] = React.useState<number | null>(null);
  const [sidebarQuery, setSidebarQuery] = React.useState("");

  const [formOpen, setFormOpen] = React.useState(false);
  const [editingPost, setEditingPost] = React.useState<SurgeonSchedulePostV2 | null>(null);
  const [preselectedSurgeonId, setPreselectedSurgeonId] = React.useState<number | null>(null);
  const [exceptionsPost, setExceptionsPost] = React.useState<SurgeonSchedulePostV2 | null>(null);

  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const surgeonsQuery = useQuery({ queryKey: ["surgeons-list-active"], queryFn: () => getSurgeons({ active: true }) });
  const instrumentistsQuery = useQuery({ queryKey: ["instrumentists-list-active"], queryFn: () => getInstrumentists({ active: true }) });
  const postsQuery = useQuery({
    queryKey: ["planning-v2", "surgeon-posts", "all-active"],
    queryFn: () => getSurgeonPosts({ active: true }),
  });

  const surgeonOptions: SearchableOption[] = React.useMemo(
    () => (surgeonsQuery.data?.items ?? []).map((s) => ({ id: s.id, label: s.displayName })),
    [surgeonsQuery.data],
  );
  const instrumentistOptions: SearchableOption[] = React.useMemo(
    () => (instrumentistsQuery.data?.items ?? []).map((i) => ({ id: i.id, label: i.displayName })),
    [instrumentistsQuery.data],
  );

  function invalidatePosts() {
    qc.invalidateQueries({ queryKey: ["planning-v2", "surgeon-posts"] });
  }

  const createMutation = useMutation({
    mutationFn: (input: SurgeonPostInput) => createSurgeonPost(input),
    onSuccess: () => { toast.success("Poste créé"); invalidatePosts(); setFormOpen(false); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const updateMutation = useMutation({
    mutationFn: (input: SurgeonPostInput) => updateSurgeonPost(editingPost!.id, input),
    onSuccess: () => { toast.success("Poste mis à jour"); invalidatePosts(); setFormOpen(false); setEditingPost(null); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const toggleActiveMutation = useMutation({
    mutationFn: async (post: SurgeonSchedulePostV2): Promise<void> => {
      if (post.active) await deactivateSurgeonPost(post.id);
      else await reactivateSurgeonPost(post.id);
    },
    onSuccess: (_, post) => { toast.success(post.active ? "Poste désactivé" : "Poste réactivé"); invalidatePosts(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const groups: SurgeonGroup[] = React.useMemo(() => {
    const map = new Map<number, SurgeonGroup>();
    for (const post of postsQuery.data?.items ?? []) {
      const key = post.surgeon.id;
      const label = post.surgeon.name ?? post.surgeon.email;
      if (!map.has(key)) map.set(key, { id: key, label, posts: [] });
      map.get(key)!.posts.push(post);
    }
    return Array.from(map.values()).sort((a, b) => a.label.localeCompare(b.label));
  }, [postsQuery.data]);

  const filteredGroups = React.useMemo(() => {
    if (!sidebarQuery.trim()) return groups;
    const q = sidebarQuery.trim().toLowerCase();
    return groups.filter((g) => g.label.toLowerCase().includes(q));
  }, [groups, sidebarQuery]);

  React.useEffect(() => {
    if (selectedSurgeonId === null && groups.length > 0) {
      setSelectedSurgeonId(groups[0].id);
    }
  }, [groups, selectedSurgeonId]);

  const selectedGroup = groups.find((g) => g.id === selectedSurgeonId) ?? null;

  // Frontend-only "fin de poste proche" — not a PlanningAlert (see EndingSoonAlertCard).
  // Lives here in Postes, not Alertes, so it can never be confused with a real backend
  // alert requiring acknowledge/resolve/reassign/open-as-available (Batch 13 decision).
  const endingSoonPosts = React.useMemo(
    () => findEndingSoonPosts(postsQuery.data?.items ?? []),
    [postsQuery.data],
  );

  function openAddFor(surgeonId: number | null) {
    setEditingPost(null);
    setPreselectedSurgeonId(surgeonId);
    setFormOpen(true);
  }

  if (postsQuery.isLoading || surgeonsQuery.isLoading) {
    return <Box sx={{ display: "flex", justifyContent: "center", py: 8 }}><CircularProgress /></Box>;
  }
  if (postsQuery.isError) {
    return <Alert severity="error" action={<Button onClick={() => postsQuery.refetch()}>Réessayer</Button>}>{extractErrorV2(postsQuery.error)}</Alert>;
  }

  const layoutSwitcher = (
    <Stack direction="row" spacing={0.5} sx={{ bgcolor: "#F1F4F7", borderRadius: planningV2Radii.button, p: 0.5 }}>
      <LayoutButton icon={<ViewModuleOutlinedIcon fontSize="small" />} active={layout === "cards"} onClick={() => setLayout("cards")} title="Cartes par chirurgien" />
      <LayoutButton icon={<ViewListOutlinedIcon fontSize="small" />} active={layout === "rows"} onClick={() => setLayout("rows")} title="Liste compacte" />
      <LayoutButton icon={<ViewSidebarOutlinedIcon fontSize="small" />} active={layout === "split"} onClick={() => setLayout("split")} title="Vue détaillée" />
    </Stack>
  );

  return (
    <>
      {endingSoonPosts.length > 0 && (
        <Stack spacing={1} sx={{ mb: 2 }}>
          {endingSoonPosts.map((post) => (
            <EndingSoonAlertCard key={post.id} post={post} />
          ))}
        </Stack>
      )}

      {layout === "split" ? (
        <Stack direction="row" sx={{ height: "calc(100vh - 230px)", minHeight: 480, mx: { xs: -2, md: -3 }, mt: { xs: -2, md: -3 } }}>
          <Box sx={{ width: 280, flex: "none", borderRight: `1px solid ${planningV2Colors.cardBorder}`, overflowY: "auto", p: 1.5 }}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ px: 1, mb: 1 }}>
              <Typography sx={{ fontSize: 11, fontWeight: 700, letterSpacing: "0.06em", textTransform: "uppercase", color: planningV2Colors.textSecondary }}>
                Chirurgiens · {groups.length}
              </Typography>
              {layoutSwitcher}
            </Stack>
            <TextField
              size="small" fullWidth placeholder="Rechercher…"
              value={sidebarQuery} onChange={(e) => setSidebarQuery(e.target.value)}
              sx={{ mb: 1, "& .MuiOutlinedInput-root": { borderRadius: planningV2Radii.button, fontSize: 13 } }}
              slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlinedIcon sx={{ fontSize: 16 }} /></InputAdornment> } }}
            />
            <Stack spacing={0.25}>
              {filteredGroups.map((g) => {
                const endingSoon = g.posts.some((p) => isEndingSoon(p.endDate));
                const colors = avatarColorFor(g.label);
                const selected = g.id === selectedSurgeonId;
                return (
                  <Box
                    key={g.id}
                    component="button"
                    onClick={() => setSelectedSurgeonId(g.id)}
                    sx={{
                      display: "flex", alignItems: "center", gap: 1.25, width: "100%", textAlign: "left",
                      border: "none", background: selected ? planningV2Colors.selectedBg : "transparent",
                      borderRadius: planningV2Radii.button, p: 1, cursor: "pointer", fontFamily: "inherit",
                      "&:hover": { background: selected ? planningV2Colors.selectedBg : "#F8FAFC" },
                    }}
                  >
                    <Box sx={{ position: "relative", flex: "none" }}>
                      <Box sx={{ width: 34, height: 34, borderRadius: "10px", bgcolor: colors.bg, color: colors.fg, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12.5, fontWeight: 700 }}>
                        {initialsFor(g.label)}
                      </Box>
                      {endingSoon && (
                        <Box sx={{ position: "absolute", top: -2, right: -2, width: 9, height: 9, borderRadius: "999px", bgcolor: planningV2Colors.warnDot, border: "2px solid #fff" }} />
                      )}
                    </Box>
                    <Box sx={{ minWidth: 0, flex: 1 }}>
                      <Typography noWrap sx={{ fontSize: 13.5, fontWeight: 600, color: planningV2Colors.textTitle }}>{g.label}</Typography>
                      <Typography sx={{ fontSize: 11.5, color: planningV2Colors.textSecondary }}>{g.posts.length} poste{g.posts.length > 1 ? "s" : ""}</Typography>
                    </Box>
                  </Box>
                );
              })}
              {filteredGroups.length === 0 && (
                <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textSecondary, textAlign: "center", py: 3 }}>Aucun résultat</Typography>
              )}
            </Stack>
          </Box>

          <Box sx={{ flex: 1, overflowY: "auto", p: 4 }}>
            {selectedGroup ? (
              <>
                <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 2.75 }}>
                  <Stack direction="row" spacing={1.75} alignItems="center">
                    <Box sx={{
                      width: 46, height: 46, borderRadius: "13px",
                      bgcolor: avatarColorFor(selectedGroup.label).bg, color: avatarColorFor(selectedGroup.label).fg,
                      display: "flex", alignItems: "center", justifyContent: "center", fontSize: 16, fontWeight: 700,
                    }}>
                      {initialsFor(selectedGroup.label)}
                    </Box>
                    <Box>
                      <Typography sx={{ fontSize: 21, fontWeight: 800, letterSpacing: "-0.02em" }}>{selectedGroup.label}</Typography>
                      <Typography sx={{ fontSize: 13, color: planningV2Colors.textMuted, mt: 0.4 }}>
                        {selectedGroup.posts.length} poste{selectedGroup.posts.length > 1 ? "s" : ""}
                      </Typography>
                    </Box>
                  </Stack>
                  <AddPostButton label={`Ajouter un poste pour ${firstName(selectedGroup.label)}`} onClick={() => openAddFor(selectedGroup.id)} />
                </Stack>
                <Stack spacing={1.5} sx={{ maxWidth: 760 }}>
                  {selectedGroup.posts.map((post) => (
                    <PostCard
                      key={post.id}
                      post={post}
                      variant="split"
                      onEdit={(p) => { setEditingPost(p); setPreselectedSurgeonId(null); setFormOpen(true); }}
                      onToggleActive={(p) => toggleActiveMutation.mutate(p)}
                      onManageExceptions={(p) => setExceptionsPost(p)}
                    />
                  ))}
                </Stack>
              </>
            ) : (
              <EmptyPostsState onAdd={() => openAddFor(null)} />
            )}
          </Box>
        </Stack>
      ) : (
        <Stack spacing={3}>
          <Stack direction="row" justifyContent="space-between" alignItems="flex-end" flexWrap="wrap" gap={1.5}>
            <Box>
              <Typography sx={{ fontSize: 22, fontWeight: 800, letterSpacing: "-0.02em" }}>Postes</Typography>
              <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted, mt: 0.5 }}>
                Les postes récurrents, organisés par chirurgien.
              </Typography>
            </Box>
            <Stack direction="row" spacing={1.5} alignItems="center">
              {layoutSwitcher}
              <AddPostButton label="Ajouter un poste" onClick={() => openAddFor(null)} />
            </Stack>
          </Stack>

          {groups.length === 0 ? (
            <EmptyPostsState onAdd={() => openAddFor(null)} />
          ) : layout === "cards" ? (
            groups.map((g) => (
              <Box key={g.id}>
                <Stack direction="row" alignItems="center" spacing={1.5} sx={{ mb: 1.5 }}>
                  <Box sx={{ width: 38, height: 38, borderRadius: "11px", bgcolor: avatarColorFor(g.label).bg, color: avatarColorFor(g.label).fg, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 13.5, fontWeight: 700 }}>
                    {initialsFor(g.label)}
                  </Box>
                  <Typography sx={{ fontSize: 15.5, fontWeight: 700 }}>{g.label}</Typography>
                  <Typography sx={{ fontSize: 12, fontWeight: 600, color: planningV2Colors.textMuted, bgcolor: "#F1F4F7", px: 1.25, py: 0.4, borderRadius: planningV2Radii.pill }}>
                    {g.posts.length} poste{g.posts.length > 1 ? "s" : ""}
                  </Typography>
                </Stack>
                <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(296px, 1fr))", gap: 1.75 }}>
                  {g.posts.map((post) => (
                    <PostCard
                      key={post.id}
                      post={post}
                      onEdit={(p) => { setEditingPost(p); setPreselectedSurgeonId(null); setFormOpen(true); }}
                      onToggleActive={(p) => toggleActiveMutation.mutate(p)}
                      onManageExceptions={(p) => setExceptionsPost(p)}
                    />
                  ))}
                  <Box
                    component="button"
                    onClick={() => openAddFor(g.id)}
                    sx={{
                      display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 1,
                      minHeight: 150, border: "1.5px dashed #DDE2E8", borderRadius: planningV2Radii.card, background: "transparent",
                      cursor: "pointer", color: planningV2Colors.textSecondary, fontFamily: "inherit", fontSize: 12.5, fontWeight: 600,
                      "&:hover": { borderColor: planningV2Colors.brand, color: planningV2Colors.brand, background: "#fff" },
                    }}
                  >
                    <AddIcon fontSize="small" />
                    Ajouter un poste
                  </Box>
                </Box>
              </Box>
            ))
          ) : (
            groups.map((g) => (
              <Box key={g.id}>
                <Stack direction="row" alignItems="center" spacing={1.5} sx={{ mb: 1.25 }}>
                  <Box sx={{ width: 38, height: 38, borderRadius: "11px", bgcolor: avatarColorFor(g.label).bg, color: avatarColorFor(g.label).fg, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 13.5, fontWeight: 700 }}>
                    {initialsFor(g.label)}
                  </Box>
                  <Typography sx={{ fontSize: 15.5, fontWeight: 700 }}>{g.label}</Typography>
                  <Typography sx={{ fontSize: 12, fontWeight: 600, color: planningV2Colors.textMuted, bgcolor: "#F1F4F7", px: 1.25, py: 0.4, borderRadius: planningV2Radii.pill }}>
                    {g.posts.length} poste{g.posts.length > 1 ? "s" : ""}
                  </Typography>
                </Stack>
                <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.card, overflow: "hidden", boxShadow: planningV2Shadows.card }}>
                  {g.posts.map((post, idx) => (
                    <PostRow
                      key={post.id}
                      post={post}
                      divider={idx < g.posts.length - 1}
                      onEdit={(p) => { setEditingPost(p); setPreselectedSurgeonId(null); setFormOpen(true); }}
                      onToggleActive={(p) => toggleActiveMutation.mutate(p)}
                      onManageExceptions={(p) => setExceptionsPost(p)}
                    />
                  ))}
                </Box>
              </Box>
            ))
          )}
        </Stack>
      )}

      <PostFormDialog
        open={formOpen}
        onClose={() => { setFormOpen(false); setEditingPost(null); setPreselectedSurgeonId(null); }}
        onSubmit={(input) => (editingPost ? updateMutation.mutate(input) : createMutation.mutate(input))}
        submitting={createMutation.isPending || updateMutation.isPending}
        sites={sitesQuery.data ?? []}
        surgeons={surgeonOptions}
        instrumentists={instrumentistOptions}
        editingPost={editingPost}
        preselectedSurgeonId={preselectedSurgeonId}
      />

      <ExceptionsSheet
        open={!!exceptionsPost}
        onClose={() => setExceptionsPost(null)}
        post={exceptionsPost}
        instrumentists={instrumentistOptions}
      />
    </>
  );
}

function LayoutButton({ icon, active, onClick, title }: { icon: React.ReactNode; active: boolean; onClick: () => void; title: string }) {
  return (
    <IconButton
      size="small" onClick={onClick} title={title}
      sx={{
        borderRadius: "8px", color: active ? planningV2Colors.textTitle : planningV2Colors.textSecondary,
        bgcolor: active ? "#fff" : "transparent", boxShadow: active ? "0 1px 2px rgba(22,32,43,.08)" : "none",
      }}
    >
      {icon}
    </IconButton>
  );
}

function AddPostButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <Button
      variant="contained" disableElevation startIcon={<AddIcon />} onClick={onClick}
      sx={{
        height: 38, px: 2, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600, fontSize: 13,
        bgcolor: planningV2Colors.brand, boxShadow: planningV2Shadows.button,
        "&:hover": { bgcolor: planningV2Colors.brandHover },
      }}
    >
      {label}
    </Button>
  );
}

function EmptyPostsState({ onAdd }: { onAdd: () => void }) {
  return (
    <Box sx={{ bgcolor: "#fff", border: "1px dashed #DDE2E8", borderRadius: planningV2Radii.cardLg, p: 7, textAlign: "center" }}>
      <Typography sx={{ fontSize: 15, fontWeight: 700, mb: 1 }}>Aucun poste pour le moment</Typography>
      <Typography sx={{ fontSize: 13, color: planningV2Colors.textMuted, mb: 2.5 }}>
        Créez le premier poste récurrent pour commencer à générer le planning.
      </Typography>
      <Button
        variant="contained" disableElevation startIcon={<AddIcon />} onClick={onAdd}
        sx={{ bgcolor: planningV2Colors.brand, textTransform: "none", fontWeight: 600, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
      >
        Créer le premier poste
      </Button>
    </Box>
  );
}

function PostRow({
  post, divider, onEdit, onToggleActive, onManageExceptions,
}: {
  post: SurgeonSchedulePostV2; divider: boolean;
  onEdit: (p: SurgeonSchedulePostV2) => void; onToggleActive: (p: SurgeonSchedulePostV2) => void;
  onManageExceptions: (p: SurgeonSchedulePostV2) => void;
}) {
  const [menuAnchor, setMenuAnchor] = React.useState<HTMLElement | null>(null);
  const PERIOD_LABELS: Record<string, string> = { MATIN: "Matin", APRES_MIDI: "Après-midi", JOURNEE: "Journée" };
  return (
    <Stack
      direction="row" alignItems="center" spacing={1.5}
      sx={{ px: 2.25, py: 1.5, borderBottom: divider ? `1px solid ${planningV2Colors.divider}` : "none", opacity: post.active ? 1 : 0.55 }}
    >
      <Box sx={{ width: 3, height: 30, borderRadius: "999px", flex: "none", bgcolor: post.type === "BLOCK" ? planningV2Colors.infoFg : "#7C4FCC" }} />
      <Typography sx={{ width: 120, flex: "none", fontSize: 13.5, fontWeight: 700 }} noWrap>{post.site.name}</Typography>
      <Box sx={{ width: 150, flex: "none", fontSize: 12.5, color: planningV2Colors.textStrong }}>
        <Typography component="span" sx={{ fontWeight: 600, fontSize: 12.5 }}>{PERIOD_LABELS[post.period]}</Typography>
      </Box>
      <Typography sx={{ flex: 1, fontSize: 12.5, color: planningV2Colors.textBody, minWidth: 0 }} noWrap>
        {post.recurrence.weekdays.length > 0 ? post.recurrence.weekdays.length + " j/sem" : "—"}
      </Typography>
      <Stack direction="row" alignItems="center" spacing={0.75} sx={{ width: 150, flex: "none" }}>
        {post.instrumentist ? (
          <Typography noWrap sx={{ fontSize: 12.5, fontWeight: 600 }}>{post.instrumentist.name ?? post.instrumentist.email}</Typography>
        ) : (
          <Typography sx={{ fontSize: 11.5, fontWeight: 700, color: planningV2Colors.warnFg }}>À assigner</Typography>
        )}
      </Stack>
      <Button size="small" onClick={() => onManageExceptions(post)} sx={{ textTransform: "none", fontSize: 12, fontWeight: 700, color: planningV2Colors.textSecondary }}>
        Exceptions
      </Button>
      <IconButton size="small" onClick={(e) => setMenuAnchor(e.currentTarget)}><MoreHorizIcon fontSize="small" /></IconButton>
      {menuAnchor && (
        <RowMenu anchor={menuAnchor} onClose={() => setMenuAnchor(null)} post={post} onEdit={onEdit} onToggleActive={onToggleActive} />
      )}
    </Stack>
  );
}

function RowMenu({
  anchor, onClose, post, onEdit, onToggleActive,
}: {
  anchor: HTMLElement; onClose: () => void; post: SurgeonSchedulePostV2;
  onEdit: (p: SurgeonSchedulePostV2) => void; onToggleActive: (p: SurgeonSchedulePostV2) => void;
}) {
  return (
    <Menu anchorEl={anchor} open onClose={onClose}>
      <MenuItem onClick={() => { onClose(); onEdit(post); }}>Modifier</MenuItem>
      <MenuItem onClick={() => { onClose(); onToggleActive(post); }}>{post.active ? "Désactiver" : "Réactiver"}</MenuItem>
    </Menu>
  );
}

function firstName(fullName: string): string {
  return fullName.split(" ")[0] || fullName;
}

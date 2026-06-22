import * as React from "react";
import {
  Alert, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, FormControl, IconButton, InputLabel, MenuItem, Select, Stack,
  TextField, Tooltip, Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteOutlineIcon from "@mui/icons-material/DeleteOutline";
import EditOutlinedIcon from "@mui/icons-material/EditOutlined";
import PlaceOutlinedIcon from "@mui/icons-material/PlaceOutlined";
import CloseIcon from "@mui/icons-material/Close";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { fetchSites } from "../../sites/api/sites.api";
import {
  getSiteGroups, createSiteGroup, renameSiteGroup, deleteSiteGroup,
  addSiteToGroup, removeSiteFromGroup, extractErrorV2,
} from "../api/planningV2.api";
import type { SiteGroupV2 } from "../api/planningV2.types";
import { useToast } from "../../../ui/toast/useToast";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

export function SiteGroupSettings() {
  const toast = useToast();
  const qc = useQueryClient();

  const [createOpen, setCreateOpen] = React.useState(false);
  const [newName, setNewName] = React.useState("");

  const [renaming, setRenaming] = React.useState<SiteGroupV2 | null>(null);
  const [renameValue, setRenameValue] = React.useState("");

  const [addSiteFor, setAddSiteFor] = React.useState<SiteGroupV2 | null>(null);
  const [siteToAdd, setSiteToAdd] = React.useState<number | "">("");

  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const groupsQuery = useQuery({ queryKey: ["planning-v2", "site-groups"], queryFn: getSiteGroups });

  function invalidate() {
    qc.invalidateQueries({ queryKey: ["planning-v2", "site-groups"] });
  }

  const createMutation = useMutation({
    mutationFn: () => createSiteGroup(newName.trim()),
    onSuccess: () => { toast.success("Groupe créé"); invalidate(); setCreateOpen(false); setNewName(""); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const renameMutation = useMutation({
    mutationFn: () => renameSiteGroup(renaming!.id, renameValue.trim()),
    onSuccess: () => { toast.success("Groupe renommé"); invalidate(); setRenaming(null); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteSiteGroup(id),
    onSuccess: () => { toast.success("Groupe supprimé"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const addSiteMutation = useMutation({
    mutationFn: () => addSiteToGroup(addSiteFor!.id, siteToAdd as number),
    onSuccess: () => { toast.success("Site ajouté"); invalidate(); setAddSiteFor(null); setSiteToAdd(""); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const removeSiteMutation = useMutation({
    mutationFn: ({ groupId, siteId }: { groupId: number; siteId: number }) => removeSiteFromGroup(groupId, siteId),
    onSuccess: () => { toast.success("Site retiré"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const groups = groupsQuery.data?.items ?? [];

  return (
    <Box>
      {groupsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>
      ) : groupsQuery.isError ? (
        <Alert severity="error">{extractErrorV2(groupsQuery.error)}</Alert>
      ) : (
        <Stack spacing={1.75}>
          {groups.map((g) => (
            <Box key={g.id} sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, p: 2.25, boxShadow: planningV2Shadows.card }}>
              <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1.6 }}>
                <Stack direction="row" alignItems="center" spacing={1.1}>
                  <Typography sx={{ fontSize: 14.5, fontWeight: 700 }}>{g.name}</Typography>
                  <Typography sx={{ fontSize: 11.5, fontWeight: 600, color: planningV2Colors.textMuted, bgcolor: "#F1F4F7", px: 1.1, py: 0.3, borderRadius: planningV2Radii.pill }}>
                    {g.sites.length} site{g.sites.length > 1 ? "s" : ""}
                  </Typography>
                </Stack>
                <Stack direction="row" spacing={0.5}>
                  <Tooltip title="Renommer">
                    <IconButton size="small" onClick={() => { setRenaming(g); setRenameValue(g.name); }} sx={{ color: planningV2Colors.textSecondary, "&:hover": { color: planningV2Colors.brand, bgcolor: "#F1F4F7" } }}>
                      <EditOutlinedIcon sx={{ fontSize: 16 }} />
                    </IconButton>
                  </Tooltip>
                  <Tooltip title="Supprimer le groupe">
                    <IconButton size="small" onClick={() => deleteMutation.mutate(g.id)} sx={{ color: planningV2Colors.textSecondary, "&:hover": { color: planningV2Colors.critFg, bgcolor: planningV2Colors.critBg } }}>
                      <DeleteOutlineIcon sx={{ fontSize: 16 }} />
                    </IconButton>
                  </Tooltip>
                </Stack>
              </Stack>
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="center">
                {g.sites.map((s) => (
                  <Stack
                    key={s.id} direction="row" alignItems="center" spacing={0.9}
                    sx={{ px: 1.5, py: 0.75, bgcolor: "#F8FAFC", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.pill, fontSize: 12.5, fontWeight: 600, color: planningV2Colors.textStrong }}
                  >
                    <PlaceOutlinedIcon sx={{ fontSize: 13, color: planningV2Colors.brand }} />
                    {s.name}
                    <Box
                      component="button" onClick={() => removeSiteMutation.mutate({ groupId: g.id, siteId: s.id })}
                      sx={{ border: "none", background: "transparent", cursor: "pointer", display: "flex", color: planningV2Colors.textSecondary, p: 0, "&:hover": { color: planningV2Colors.critFg } }}
                    >
                      <CloseIcon sx={{ fontSize: 13 }} />
                    </Box>
                  </Stack>
                ))}
                <Box
                  component="button" onClick={() => { setAddSiteFor(g); setSiteToAdd(""); }}
                  sx={{
                    display: "inline-flex", alignItems: "center", gap: 0.7, px: 1.5, py: 0.75, bgcolor: "transparent",
                    border: "1.5px dashed #DDE2E8", borderRadius: planningV2Radii.pill, fontFamily: "inherit", fontSize: 12.5, fontWeight: 600,
                    color: planningV2Colors.textSecondary, cursor: "pointer",
                    "&:hover": { borderColor: planningV2Colors.brand, color: planningV2Colors.brand },
                  }}
                >
                  <AddIcon sx={{ fontSize: 14 }} />
                  Ajouter un site
                </Box>
              </Stack>
            </Box>
          ))}
          <Box
            component="button" onClick={() => setCreateOpen(true)}
            sx={{
              display: "flex", alignItems: "center", justifyContent: "center", gap: 1, height: 48,
              border: "1.5px dashed #DDE2E8", borderRadius: planningV2Radii.card, background: "transparent", cursor: "pointer",
              color: planningV2Colors.textSecondary, fontFamily: "inherit", fontSize: 13, fontWeight: 600,
              "&:hover": { borderColor: planningV2Colors.brand, color: planningV2Colors.brand, background: "#fff" },
            }}
          >
            <AddIcon sx={{ fontSize: 17 }} />
            Nouveau groupe de sites
          </Box>
        </Stack>
      )}

      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: planningV2Radii.modal } } }}>
        <DialogTitle>Nouveau groupe de sites</DialogTitle>
        <DialogContent>
          <TextField autoFocus fullWidth label="Nom du groupe" value={newName} onChange={(e) => setNewName(e.target.value)} sx={{ mt: 0.5 }} />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCreateOpen(false)}>Annuler</Button>
          <Button variant="contained" disableElevation disabled={!newName.trim() || createMutation.isPending} onClick={() => createMutation.mutate()} sx={{ bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}>
            Créer
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={!!renaming} onClose={() => setRenaming(null)} maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: planningV2Radii.modal } } }}>
        <DialogTitle>Renommer le groupe</DialogTitle>
        <DialogContent>
          <TextField autoFocus fullWidth label="Nom du groupe" value={renameValue} onChange={(e) => setRenameValue(e.target.value)} sx={{ mt: 0.5 }} />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRenaming(null)}>Annuler</Button>
          <Button variant="contained" disableElevation disabled={!renameValue.trim() || renameMutation.isPending} onClick={() => renameMutation.mutate()} sx={{ bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}>
            Enregistrer
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={!!addSiteFor} onClose={() => setAddSiteFor(null)} maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: planningV2Radii.modal } } }}>
        <DialogTitle>Ajouter un site au groupe</DialogTitle>
        <DialogContent>
          <FormControl fullWidth sx={{ mt: 0.5 }}>
            <InputLabel id="add-site-label">Site</InputLabel>
            <Select labelId="add-site-label" label="Site" value={siteToAdd} onChange={(e) => setSiteToAdd(e.target.value as number)}>
              {(sitesQuery.data ?? [])
                .filter((s) => !addSiteFor?.sites.some((gs) => gs.id === s.id))
                .map((s) => <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>)}
            </Select>
          </FormControl>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setAddSiteFor(null)}>Annuler</Button>
          <Button variant="contained" disableElevation disabled={siteToAdd === "" || addSiteMutation.isPending} onClick={() => addSiteMutation.mutate()} sx={{ bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}>
            Ajouter
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}

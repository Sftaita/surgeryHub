import * as React from "react";
import {
  Autocomplete,
  Box, Button, Chip, CircularProgress, Collapse, Dialog, DialogActions, DialogContent,
  DialogTitle, FormControl, IconButton, InputLabel, MenuItem, Select,
  Stack, TextField, Tooltip, Typography,
} from "@mui/material";
import ArrowBackIcon          from "@mui/icons-material/ArrowBack";
import DeleteIcon             from "@mui/icons-material/Delete";
import WeekendIcon            from "@mui/icons-material/Weekend";
import DriveFileRenameOutlineIcon from "@mui/icons-material/DriveFileRenameOutline";
import CheckIcon              from "@mui/icons-material/Check";
import CloseIcon              from "@mui/icons-material/Close";
import ExpandMoreIcon         from "@mui/icons-material/ExpandMore";
import AddIcon                from "@mui/icons-material/Add";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getTemplate, addSlot, updateSlot, deleteSlot, renameTemplate,
  DAY_LABELS,
  type PlanningSlot,
} from "../../../features/planning-manager/api/planning.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";

// ── Design tokens ─────────────────────────────────────────────────────────────
const BG             = "#ECF2FB";
const BLUE           = "#2563EB";
const BLUE_DARK      = "#1E40AF";

// ── Timeline constants ────────────────────────────────────────────────────────
const HOUR_H         = 64;   // px per hour
const TL_START       = 6;    // timeline starts at 06:00
const TL_END         = 22;   // timeline ends at 22:00
const SCROLL_TO      = 8;    // default scroll to 08:00 on open
const VISIBLE_HOURS  = 10;   // viewport height in hours
const TIME_AXIS_W    = 52;   // px for hour-label column
const SLOT_GAP       = 3;    // px gap between parallel slot columns

const WEEKDAYS = [1, 2, 3, 4, 5];
const WEEKEND  = [6, 7];
const ALL_DAYS = [...WEEKDAYS, ...WEEKEND];

// ── Helpers ───────────────────────────────────────────────────────────────────
function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}
function toMin(t: string): number {
  const [h, m] = t.split(":").map(Number);
  return h * 60 + (m || 0);
}
function overlaps(a: PlanningSlot, b: PlanningSlot): boolean {
  return toMin(a.startTime) < toMin(b.endTime) && toMin(b.startTime) < toMin(a.endTime);
}
function derivePeriod(startTime: string): "AM" | "PM" {
  return toMin(startTime) < 13 * 60 ? "AM" : "PM";
}
function fmt(t: string): string {
  return t.slice(0, 5);
}
function minToTime(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

// ── Column layout (Google Calendar–style) ────────────────────────────────────
interface SlotWithLayout extends PlanningSlot {
  colIndex: number;
  colTotal: number;
}

function computeLayout(slots: PlanningSlot[]): SlotWithLayout[] {
  if (!slots.length) return [];
  const n = slots.length;

  // Overlap adjacency matrix
  const adj = Array.from({ length: n }, () => new Array(n).fill(false));
  for (let i = 0; i < n; i++)
    for (let j = i + 1; j < n; j++)
      if (overlaps(slots[i], slots[j]))
        adj[i][j] = adj[j][i] = true;

  // Connected components (overlap groups)
  const visited = new Array(n).fill(false);
  const groups: number[][] = [];
  for (let i = 0; i < n; i++) {
    if (!visited[i]) {
      const group: number[] = [];
      const q = [i];
      visited[i] = true;
      while (q.length) {
        const cur = q.shift()!;
        group.push(cur);
        for (let j = 0; j < n; j++)
          if (adj[cur][j] && !visited[j]) { visited[j] = true; q.push(j); }
      }
      groups.push(group);
    }
  }

  // Greedy column assignment per group
  const colIdx   = new Array(n).fill(0);
  const colTotal = new Array(n).fill(1);
  for (const group of groups) {
    const sorted   = [...group].sort((a, b) => toMin(slots[a].startTime) - toMin(slots[b].startTime));
    const assigned = new Map<number, number>();
    for (const idx of sorted) {
      const used = new Set<number>();
      for (const other of sorted)
        if (adj[idx][other] && assigned.has(other)) used.add(assigned.get(other)!);
      let col = 0;
      while (used.has(col)) col++;
      assigned.set(idx, col);
    }
    const total = Math.max(...assigned.values()) + 1;
    for (const idx of group) { colIdx[idx] = assigned.get(idx)!; colTotal[idx] = total; }
  }

  return slots.map((s, i) => ({ ...s, colIndex: colIdx[i], colTotal: colTotal[i] }));
}

// ── Form types ────────────────────────────────────────────────────────────────
interface SlotForm {
  surgeonId: string;
  instrumentistId: string;
  missionType: "BLOCK" | "CONSULTATION";
  startTime: string;
  endTime: string;
}
const EMPTY_FORM: SlotForm = {
  surgeonId: "", instrumentistId: "", missionType: "BLOCK",
  startTime: "08:00", endTime: "10:00",
};

type UserOption = { id: number; firstname: string; lastname: string; email: string };
function displayName(u: UserOption): string {
  const n = `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim();
  return n || u.email;
}

// ── Page ──────────────────────────────────────────────────────────────────────
export default function PlanningTemplateEditorPage() {
  const { id }    = useParams<{ id: string }>();
  const navigate  = useNavigate();
  const toast     = useToast();
  const qc        = useQueryClient();

  const [showWeekend,     setShowWeekend]     = React.useState(false);
  const [editingTitle,    setEditingTitle]    = React.useState(false);
  const [titleDraft,      setTitleDraft]      = React.useState("");
  const [optimisticLabel, setOptimisticLabel] = React.useState<string | null | undefined>(undefined);
  const [openDay,         setOpenDay]         = React.useState<number>(1);

  const [addOpen, setAddOpen] = React.useState(false);
  const [addDay,  setAddDay]  = React.useState(1);
  const [addForm, setAddForm] = React.useState<SlotForm>(EMPTY_FORM);

  const [editOpen,    setEditOpen]    = React.useState(false);
  const [editSlot,    setEditSlot]    = React.useState<PlanningSlot | null>(null);
  const [editForm,    setEditForm]    = React.useState<SlotForm>(EMPTY_FORM);

  // ── Queries ───────────────────────────────────────────────────────────────
  const templateQ = useQuery({
    queryKey: ["planning-template", Number(id)],
    queryFn:  () => getTemplate(Number(id)),
    enabled:  !!id,
  });
  const surgeonsQ = useQuery({
    queryKey: ["surgeons-list"],
    queryFn:  async () => { const r = await apiClient.get("/api/surgeons"); return r.data.items as UserOption[]; },
  });
  const instQ = useQuery({
    queryKey: ["instrumentists-list"],
    queryFn:  async () => { const r = await apiClient.get("/api/instrumentists"); return r.data.items as UserOption[]; },
  });

  // ── Mutations ─────────────────────────────────────────────────────────────
  const addMut = useMutation({
    mutationFn: () => addSlot(Number(id), {
      dayOfWeek:       addDay,
      period:          derivePeriod(addForm.startTime),
      startTime:       addForm.startTime + ":00",
      endTime:         addForm.endTime   + ":00",
      surgeonId:       Number(addForm.surgeonId),
      missionType:     addForm.missionType,
      instrumentistId: addForm.instrumentistId ? Number(addForm.instrumentistId) : null,
    }),
    onSuccess: () => {
      toast.success("Créneau ajouté");
      qc.invalidateQueries({ queryKey: ["planning-template", Number(id)] });
      setAddOpen(false);
      setAddForm(EMPTY_FORM);
    },
    onError: (err) => toast.error(extractError(err)),
  });
  const editMut = useMutation({
    mutationFn: () => updateSlot(Number(id), editSlot!.id, {
      surgeonId:       Number(editForm.surgeonId),
      instrumentistId: editForm.instrumentistId ? Number(editForm.instrumentistId) : null,
      missionType:     editForm.missionType,
      startTime:       editForm.startTime + ":00",
      endTime:         editForm.endTime   + ":00",
      period:          derivePeriod(editForm.startTime),
    }),
    onSuccess: () => {
      toast.success("Créneau mis à jour");
      qc.invalidateQueries({ queryKey: ["planning-template", Number(id)] });
      setEditOpen(false);
      setEditSlot(null);
    },
    onError: (err) => toast.error(extractError(err)),
  });
  const delMut = useMutation({
    mutationFn: (slotId: number) => deleteSlot(Number(id), slotId),
    onSuccess: () => {
      toast.success("Créneau supprimé");
      qc.invalidateQueries({ queryKey: ["planning-template", Number(id)] });
    },
    onError: (err) => toast.error(extractError(err)),
  });
  const renameMut = useMutation({
    mutationFn: (label: string | null) => renameTemplate(Number(id), label),
  });

  // ── Title edit ────────────────────────────────────────────────────────────
  function startEditTitle() {
    setTitleDraft(optimisticLabel !== undefined ? (optimisticLabel ?? "") : (tpl?.label ?? ""));
    setEditingTitle(true);
  }
  function commitTitle() {
    const next = titleDraft.trim() || null;
    const prev = optimisticLabel !== undefined ? optimisticLabel : (tpl?.label ?? null);
    setEditingTitle(false);
    setOptimisticLabel(next);
    renameMut.mutate(next, {
      onSuccess: () => { setOptimisticLabel(undefined); qc.invalidateQueries({ queryKey: ["planning-template", Number(id)] }); },
      onError:   (err) => { setOptimisticLabel(prev); toast.error(extractError(err)); setTitleDraft(next ?? ""); setEditingTitle(true); },
    });
  }
  function cancelTitle() { setEditingTitle(false); }

  // ── Slot actions ──────────────────────────────────────────────────────────
  function openAdd(day: number, startTime = "08:00", endTime = "10:00") {
    setAddDay(day);
    setAddForm({ ...EMPTY_FORM, startTime, endTime });
    setAddOpen(true);
  }
  function openEdit(slot: PlanningSlot) {
    setEditSlot(slot);
    setEditForm({
      surgeonId:       String(slot.surgeon.id),
      instrumentistId: String((slot.instrumentist as any)?.id ?? ""),
      missionType:     slot.missionType,
      startTime:       fmt(slot.startTime),
      endTime:         fmt(slot.endTime),
    });
    setEditOpen(true);
  }

  // ── Derived ───────────────────────────────────────────────────────────────
  const tpl      = templateQ.data;
  const rawSlots = tpl?.slots ?? [];

  const daySlots: Record<number, PlanningSlot[]> = {};
  for (const d of ALL_DAYS) daySlots[d] = [];
  rawSlots.forEach((s) => daySlots[s.dayOfWeek]?.push(s));

  const weekendHasSlots = WEEKEND.some((d) => daySlots[d].length > 0);
  const visibleDays     = showWeekend || weekendHasSlots ? ALL_DAYS : WEEKDAYS;
  const displayedLabel  = optimisticLabel !== undefined ? optimisticLabel : tpl?.label;
  const typeLabel       = tpl?.type === "PAIR" ? "Semaines paires" : tpl?.type === "IMPAIR" ? "Semaines impaires" : "Toutes semaines";
  const headerTitle     = displayedLabel || `${tpl?.site?.name ?? "Template"} — ${typeLabel}`;
  const todayDow        = React.useMemo(() => { const d = new Date().getDay(); return d === 0 ? 7 : d; }, []);

  if (templateQ.isLoading) return (
    <Box sx={{ display: "flex", justifyContent: "center", alignItems: "center", height: "60vh" }}>
      <CircularProgress />
    </Box>
  );
  if (!tpl) return <Typography sx={{ p: 3 }}>Template introuvable</Typography>;

  return (
    <Box sx={{ bgcolor: BG, minHeight: "calc(100vh - 64px)", p: { xs: 2, md: "24px 28px" } }}>

      {/* ── Header ──────────────────────────────────────────────────────── */}
      <Box sx={{ mb: 3 }}>
        <Button
          startIcon={<ArrowBackIcon sx={{ fontSize: "14px !important" }} />}
          onClick={() => navigate("/app/m/planning/templates")}
          size="small" disableRipple
          sx={{ color: "text.secondary", fontSize: ".78rem", fontWeight: 500, mb: 0.5, minWidth: 0, px: 0, "&:hover": { bgcolor: "transparent", color: BLUE } }}
        >
          Retour
        </Button>

        <Box sx={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 2, flexWrap: "wrap" }}>
          <Box sx={{ flex: 1, minWidth: 0 }}>
            {editingTitle ? (
              <Stack direction="row" alignItems="center" spacing={0.5}>
                <TextField
                  value={titleDraft} onChange={(e) => setTitleDraft(e.target.value)}
                  size="small" placeholder={`Template — ${typeLabel}`} autoFocus
                  onKeyDown={(e) => { if (e.key === "Enter") commitTitle(); if (e.key === "Escape") cancelTitle(); }}
                  sx={{ maxWidth: 400, "& .MuiInputBase-root": { fontSize: "1.4rem", fontWeight: 800 } }}
                />
                <Tooltip title="Enregistrer">
                  <IconButton size="small" color="primary" onClick={commitTitle}><CheckIcon fontSize="small" /></IconButton>
                </Tooltip>
                <Tooltip title="Annuler">
                  <IconButton size="small" onClick={cancelTitle}><CloseIcon fontSize="small" /></IconButton>
                </Tooltip>
              </Stack>
            ) : (
              <Stack direction="row" alignItems="center" spacing={0.75}>
                <Typography variant="h5" fontWeight={800} sx={{ letterSpacing: -.5, lineHeight: 1.2 }}>
                  {headerTitle}
                </Typography>
                <Tooltip title="Renommer">
                  <IconButton size="small" onClick={startEditTitle} sx={{ opacity: .35, "&:hover": { opacity: 1 } }}>
                    <DriveFileRenameOutlineIcon sx={{ fontSize: 15 }} />
                  </IconButton>
                </Tooltip>
              </Stack>
            )}
            {displayedLabel && (
              <Typography variant="body2" color="text.secondary" sx={{ mt: 0.25, fontSize: ".8rem" }}>
                {typeLabel}
              </Typography>
            )}
          </Box>

          <Stack direction="row" spacing={1} alignItems="center" sx={{ flexShrink: 0, mt: 0.5 }}>
            {tpl.site && (
              <Chip label={tpl.site.name} variant="outlined" size="small"
                sx={{ borderRadius: 999, fontSize: ".78rem", fontWeight: 500, borderColor: "divider" }} />
            )}
            <Button
              variant={showWeekend || weekendHasSlots ? "contained" : "outlined"}
              disableElevation
              startIcon={<WeekendIcon fontSize="small" />}
              size="small"
              onClick={() => setShowWeekend((v) => !v)}
              disabled={weekendHasSlots}
              sx={{
                borderRadius: 999, fontWeight: 700, fontSize: ".75rem", letterSpacing: .5, textTransform: "uppercase",
                ...(!(showWeekend || weekendHasSlots) && { color: BLUE, borderColor: BLUE, "&:hover": { bgcolor: "#EFF6FF", borderColor: BLUE } }),
              }}
            >
              Week-end
            </Button>
          </Stack>
        </Box>
      </Box>

      {/* ── Accordion ───────────────────────────────────────────────────── */}
      <Stack spacing={1.5}>
        {visibleDays.map((day) => (
          <DayAccordion
            key={day}
            day={day}
            isToday={day === todayDow}
            isOpen={openDay === day}
            onToggle={() => setOpenDay((prev) => prev === day ? -1 : day)}
            slots={daySlots[day]}
            onAdd={(start, end) => openAdd(day, start, end)}
            onEdit={openEdit}
            onDelete={(slotId) => delMut.mutate(slotId)}
          />
        ))}
      </Stack>

      {/* ── Add dialog ──────────────────────────────────────────────────── */}
      <SlotDialog
        open={addOpen}
        title={`Ajouter un créneau — ${DAY_LABELS[addDay]}`}
        form={addForm} setForm={setAddForm}
        surgeons={surgeonsQ.data ?? []} instrumentists={instQ.data ?? []}
        onClose={() => setAddOpen(false)}
        onSubmit={() => addMut.mutate()}
        isPending={addMut.isPending}
        submitLabel="Ajouter"
      />

      {/* ── Edit dialog ─────────────────────────────────────────────────── */}
      <SlotDialog
        open={editOpen}
        title="Modifier le créneau"
        form={editForm} setForm={setEditForm}
        surgeons={surgeonsQ.data ?? []} instrumentists={instQ.data ?? []}
        onClose={() => { setEditOpen(false); setEditSlot(null); }}
        onSubmit={() => editMut.mutate()}
        isPending={editMut.isPending}
        submitLabel="Enregistrer"
        onDelete={editSlot ? () => { delMut.mutate(editSlot.id); setEditOpen(false); setEditSlot(null); } : undefined}
      />
    </Box>
  );
}

// ── DayAccordion ──────────────────────────────────────────────────────────────
function DayAccordion({
  day, isToday, isOpen, onToggle, slots, onAdd, onEdit, onDelete,
}: {
  day:      number;
  isToday:  boolean;
  isOpen:   boolean;
  onToggle: () => void;
  slots:    PlanningSlot[];
  onAdd:    (start: string, end: string) => void;
  onEdit:   (slot: PlanningSlot) => void;
  onDelete: (slotId: number) => void;
}) {
  return (
    <Box sx={{
      bgcolor: "#fff",
      borderRadius: 2.5,
      boxShadow: "0 1px 4px rgba(0,0,0,.07)",
      border: isToday ? `1.5px solid ${BLUE}` : "1.5px solid rgba(0,0,0,.08)",
      overflow: "hidden",
    }}>

      {/* Header row */}
      <Box
        onClick={onToggle}
        sx={{
          display: "flex", alignItems: "center", gap: 1.5,
          px: 2.5, py: 1.5, cursor: "pointer",
          "&:hover": { bgcolor: "#F8FAFF" },
          transition: "background 0.15s",
          userSelect: "none",
        }}
      >
        {/* Day label */}
        <Typography sx={{
          fontSize: ".75rem", fontWeight: 800, letterSpacing: 1,
          textTransform: "uppercase", flexShrink: 0, minWidth: 80,
          color: isToday ? BLUE : "#475569",
        }}>
          {DAY_LABELS[day]}
          {isToday && <Box component="span" sx={{ ml: 0.75, fontSize: ".65rem", opacity: .8 }}>• auj.</Box>}
        </Typography>

        {/* Slot summary */}
        <Box sx={{ flex: 1, display: "flex", flexWrap: "wrap", gap: 0.75, overflow: "hidden" }}>
          {slots.length === 0 ? (
            <Typography sx={{ fontSize: ".72rem", color: "#94A3B8", fontStyle: "italic" }}>
              Aucun créneau
            </Typography>
          ) : (
            slots
              .slice()
              .sort((a, b) => toMin(a.startTime) - toMin(b.startTime))
              .map((s) => (
                <Chip
                  key={s.id}
                  label={`${s.surgeon.name}  ${fmt(s.startTime)}–${fmt(s.endTime)}`}
                  size="small"
                  sx={{
                    fontSize: ".68rem", fontWeight: 600, height: 22,
                    bgcolor: "#EFF6FF", color: BLUE,
                    border: "1px solid #BFDBFE",
                    "& .MuiChip-label": { px: 1 },
                  }}
                />
              ))
          )}
        </Box>

        {/* Actions */}
        <Stack direction="row" spacing={0.5} alignItems="center" sx={{ flexShrink: 0 }}>
          <Tooltip title="Ajouter un créneau">
            <IconButton
              size="small"
              onClick={(e) => { e.stopPropagation(); onAdd("08:00", "10:00"); }}
              sx={{ color: BLUE, opacity: .65, "&:hover": { opacity: 1, bgcolor: "#EFF6FF" } }}
            >
              <AddIcon sx={{ fontSize: 18 }} />
            </IconButton>
          </Tooltip>
          <Box sx={{
            transform: isOpen ? "rotate(180deg)" : "rotate(0deg)",
            transition: "transform 0.25s",
            display: "flex", color: "#94A3B8",
          }}>
            <ExpandMoreIcon sx={{ fontSize: 20 }} />
          </Box>
        </Stack>
      </Box>

      {/* Timeline */}
      <Collapse in={isOpen} unmountOnExit>
        <DayTimeline slots={slots} onAdd={onAdd} onEdit={onEdit} onDelete={onDelete} />
      </Collapse>
    </Box>
  );
}

// ── DayTimeline ───────────────────────────────────────────────────────────────
function DayTimeline({
  slots, onAdd, onEdit, onDelete,
}: {
  slots:    PlanningSlot[];
  onAdd:    (start: string, end: string) => void;
  onEdit:   (slot: PlanningSlot) => void;
  onDelete: (slotId: number) => void;
}) {
  const scrollRef = React.useRef<HTMLDivElement>(null);
  const totalH    = (TL_END - TL_START) * HOUR_H;
  const viewportH = VISIBLE_HOURS * HOUR_H;

  // Scroll to default position on open
  React.useEffect(() => {
    if (scrollRef.current)
      scrollRef.current.scrollTop = (SCROLL_TO - TL_START) * HOUR_H;
  }, []);

  const layout = React.useMemo(() => computeLayout(slots), [slots]);
  const hours  = Array.from({ length: TL_END - TL_START + 1 }, (_, i) => i + TL_START);

  // Detect doublon: same instrumentist on overlapping slots within the same day
  const duplicateInstIds = React.useMemo(() => {
    const byInst = new Map<number, PlanningSlot[]>();
    for (const slot of slots) {
      const id = (slot.instrumentist as any)?.id as number | undefined;
      if (!id) continue;
      byInst.set(id, [...(byInst.get(id) ?? []), slot]);
    }
    const dups = new Set<number>();
    for (const [id, list] of byInst) {
      for (let i = 0; i < list.length; i++)
        for (let j = i + 1; j < list.length; j++)
          if (overlaps(list[i], list[j])) dups.add(id);
    }
    return dups;
  }, [slots]);

  function handleBgClick(e: React.MouseEvent<HTMLDivElement>) {
    const rect    = e.currentTarget.getBoundingClientRect();
    const scrollT = scrollRef.current?.scrollTop ?? 0;
    const y       = e.clientY - rect.top + scrollT;
    const raw     = (y / HOUR_H) * 60 + TL_START * 60;
    const start   = Math.round(raw / 30) * 30;
    const end     = start + 120;
    onAdd(
      minToTime(Math.min(start, (TL_END - 1) * 60)),
      minToTime(Math.min(end,   TL_END * 60)),
    );
  }

  return (
    <Box
      ref={scrollRef}
      sx={{
        height: viewportH,
        overflowY: "auto",
        borderTop: "1px solid rgba(0,0,0,.06)",
        bgcolor: "#FAFBFF",
        // thin scrollbar
        "&::-webkit-scrollbar": { width: 4 },
        "&::-webkit-scrollbar-thumb": { bgcolor: "#CBD5E1", borderRadius: 99 },
      }}
    >
      {/* Full-height canvas */}
      <Box sx={{ position: "relative", height: totalH }}>

        {/* Hour lines + labels */}
        {hours.map((h) => (
          <Box
            key={h}
            sx={{
              position: "absolute",
              top: (h - TL_START) * HOUR_H,
              left: 0, right: 0,
              display: "flex", alignItems: "flex-start",
              pointerEvents: "none",
            }}
          >
            <Typography sx={{
              fontSize: ".64rem", fontWeight: 600,
              color: h % 2 === 0 ? "#64748B" : "#94A3B8",
              width: TIME_AXIS_W, flexShrink: 0,
              textAlign: "right", pr: 1.5, lineHeight: 1,
              mt: "-0.5em", userSelect: "none",
            }}>
              {h < TL_END ? `${String(h).padStart(2, "0")}:00` : ""}
            </Typography>
            <Box sx={{
              flex: 1,
              borderTop: `1px ${h % 2 === 0 ? "solid" : "dashed"} rgba(0,0,0,${h % 2 === 0 ? .1 : .05})`,
              mt: "-0.5px",
            }} />
          </Box>
        ))}

        {/* Clickable background + slots */}
        <Box
          onClick={handleBgClick}
          sx={{
            position: "absolute",
            left: TIME_AXIS_W, right: 0, top: 0, bottom: 0,
            cursor: "cell",
            "&:hover": { bgcolor: "rgba(37,99,235,.015)" },
          }}
        >
          {layout.map((slot) => {
            const startMin = toMin(slot.startTime);
            const endMin   = toMin(slot.endTime);
            const top    = ((startMin - TL_START * 60) / 60) * HOUR_H;
            const height = Math.max(((endMin - startMin) / 60) * HOUR_H, 28);
            const pct    = 100 / slot.colTotal;
            const left   = `calc(${slot.colIndex * pct}% + ${SLOT_GAP}px)`;
            const width  = `calc(${pct}% - ${SLOT_GAP * 2}px)`;

            const instId = (slot.instrumentist as any)?.id as number | undefined;
            return (
              <SlotBlock
                key={slot.id}
                slot={slot}
                isDuplicate={!!instId && duplicateInstIds.has(instId)}
                top={top} height={height} left={left} width={width}
                onClick={(e) => { e.stopPropagation(); onEdit(slot); }}
                onDelete={(e) => { e.stopPropagation(); onDelete(slot.id); }}
              />
            );
          })}
        </Box>
      </Box>
    </Box>
  );
}

// ── Surgeon color palette ────────────────────────────────────────────────────
const SURGEON_COLORS: { bg: string; accent: string }[] = [
  { bg: "#DBEAFE", accent: "#1D4ED8" }, // blue
  { bg: "#D1FAE5", accent: "#047857" }, // emerald
  { bg: "#EDE9FE", accent: "#6D28D9" }, // violet
  { bg: "#FCE7F3", accent: "#BE185D" }, // pink
  { bg: "#FEF3C7", accent: "#B45309" }, // amber
  { bg: "#CCFBF1", accent: "#0F766E" }, // teal
  { bg: "#FEE2E2", accent: "#B91C1C" }, // red
  { bg: "#E0E7FF", accent: "#3730A3" }, // indigo
  { bg: "#ECFCCB", accent: "#3F6212" }, // lime
  { bg: "#F3E8FF", accent: "#7E22CE" }, // purple
];

function getSurgeonColor(surgeonId: number) {
  return SURGEON_COLORS[surgeonId % SURGEON_COLORS.length];
}

// ── SlotBlock ─────────────────────────────────────────────────────────────────
function SlotBlock({
  slot, top, height, left, width, onClick, onDelete, isDuplicate = false,
}: {
  slot:        PlanningSlot;
  top:         number;
  height:      number;
  left:        string;
  width:       string;
  onClick:     (e: React.MouseEvent) => void;
  onDelete:    (e: React.MouseEvent) => void;
  isDuplicate?: boolean;
}) {
  const isBlock            = slot.missionType === "BLOCK";
  const hasInst            = !!slot.instrumentist;
  const { bg, accent }     = getSurgeonColor(slot.surgeon.id);
  const isShort            = height < 52;
  const borderColor        = isDuplicate ? "#F97316" : accent;

  return (
    <Box
      onClick={onClick}
      sx={{
        position: "absolute",
        top, left, width, height,
        bgcolor: isDuplicate ? "#FFF7ED" : bg,
        borderLeft: `3px solid ${borderColor}`,
        outline: isDuplicate ? `1.5px solid #F97316` : "none",
        borderRadius: "0 6px 6px 0",
        boxShadow: "0 1px 4px rgba(0,0,0,.09)",
        p: isShort ? "2px 26px 2px 6px" : "5px 26px 5px 8px",
        cursor: "pointer", overflow: "hidden",
        transition: "box-shadow .15s, filter .15s",
        zIndex: 1,
        "&:hover": {
          boxShadow: `0 0 0 1.5px ${accent}, 0 3px 12px rgba(0,0,0,.14)`,
          filter: "brightness(.97)",
          zIndex: 10,
        },
        // Delete button shows on hover
        "&:hover .sl-del": { opacity: 0.75 },
      }}
    >
      {/* Delete */}
      <Tooltip title="Supprimer">
        <IconButton
          className="sl-del"
          size="small"
          onClick={onDelete}
          sx={{
            position: "absolute", top: 3, right: 2,
            p: "2px", opacity: 0, transition: "opacity .15s",
            color: "error.main",
          }}
        >
          <DeleteIcon sx={{ fontSize: 12 }} />
        </IconButton>
      </Tooltip>

      {/* Time range + doublon badge */}
      <Box sx={{ display: "flex", alignItems: "center", gap: 0.5, flexWrap: "wrap" }}>
        <Typography sx={{
          fontSize: ".6rem", fontWeight: 700, color: borderColor,
          lineHeight: 1.2, whiteSpace: "nowrap",
        }}>
          {fmt(slot.startTime)} – {fmt(slot.endTime)}
        </Typography>
        {isDuplicate && (
          <Box component="span" sx={{
            bgcolor: "#F97316", color: "#fff",
            fontSize: ".55rem", fontWeight: 700,
            px: 0.6, py: "1px", borderRadius: 999,
            lineHeight: 1.5, whiteSpace: "nowrap",
          }}>
            Doublon
          </Box>
        )}
      </Box>

      {/* Surgeon */}
      <Typography noWrap sx={{
        fontSize: ".72rem", fontWeight: 700, color: "#1E293B",
        lineHeight: 1.25, mt: isShort ? 0 : "1px",
      }}>
        {slot.surgeon.name}
      </Typography>

      {/* Instrumentist */}
      {!isShort && (
        hasInst
          ? <Typography noWrap sx={{ fontSize: ".68rem", color: "#64748B", lineHeight: 1.2 }}>
              {(slot.instrumentist as any)?.name}
            </Typography>
          : <Typography sx={{ fontSize: ".65rem", color: "#F97316", fontStyle: "italic", lineHeight: 1.2 }}>
              Sans instrumentiste
            </Typography>
      )}

      {/* Type chip */}
      {height >= 72 && (
        <Box component="span" sx={{
          display: "inline-block", mt: 0.5,
          bgcolor: accent, color: "#fff",
          fontSize: ".58rem", fontWeight: 700,
          px: 0.75, py: "1px", borderRadius: 999,
          textTransform: "uppercase", letterSpacing: .4, lineHeight: 1.6,
        }}>
          {isBlock ? "Bloc" : "Consult."}
        </Box>
      )}
    </Box>
  );
}

// ── SlotDialog ────────────────────────────────────────────────────────────────
function SlotDialog({
  open, title, form, setForm, surgeons, instrumentists,
  onClose, onSubmit, isPending, submitLabel, onDelete,
}: {
  open: boolean; title: string;
  form: SlotForm; setForm: React.Dispatch<React.SetStateAction<SlotForm>>;
  surgeons: UserOption[]; instrumentists: UserOption[];
  onClose: () => void; onSubmit: () => void;
  isPending: boolean; submitLabel: string;
  onDelete?: () => void;
}) {
  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700} sx={{ pb: 1 }}>{title}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <Autocomplete
            options={surgeons}
            getOptionLabel={displayName}
            value={surgeons.find((s) => String(s.id) === form.surgeonId) ?? null}
            onChange={(_, val) => setForm((f) => ({ ...f, surgeonId: val ? String(val.id) : "" }))}
            renderInput={(params) => (
              <TextField {...params} label="Chirurgien *" size="small" required />
            )}
            noOptionsText="Aucun résultat"
            size="small"
            fullWidth
          />

          <Autocomplete
            options={instrumentists}
            getOptionLabel={displayName}
            value={instrumentists.find((i) => String(i.id) === form.instrumentistId) ?? null}
            onChange={(_, val) => setForm((f) => ({ ...f, instrumentistId: val ? String(val.id) : "" }))}
            renderInput={(params) => (
              <TextField {...params} label="Instrumentiste par défaut" size="small" />
            )}
            noOptionsText="Aucun résultat"
            clearText="Effacer"
            size="small"
            fullWidth
          />

          <FormControl size="small" fullWidth>
            <InputLabel>Type</InputLabel>
            <Select value={form.missionType} label="Type"
              onChange={(e) => setForm((f) => ({ ...f, missionType: e.target.value as "BLOCK" | "CONSULTATION" }))}>
              <MenuItem value="BLOCK">Bloc opératoire</MenuItem>
              <MenuItem value="CONSULTATION">Consultation</MenuItem>
            </Select>
          </FormControl>

          {/* Raccourcis période */}
          <Stack direction="row" spacing={1}>
            <Button
              size="small" variant={form.startTime === "08:00" && form.endTime === "12:00" ? "contained" : "outlined"}
              disableElevation
              onClick={() => setForm((f) => ({ ...f, startTime: "08:00", endTime: "12:00" }))}
              sx={{ flex: 1, borderRadius: 2, fontWeight: 600, fontSize: 12 }}
            >
              Matin (08h–12h)
            </Button>
            <Button
              size="small" variant={form.startTime === "12:00" && form.endTime === "17:00" ? "contained" : "outlined"}
              disableElevation
              onClick={() => setForm((f) => ({ ...f, startTime: "12:00", endTime: "17:00" }))}
              sx={{ flex: 1, borderRadius: 2, fontWeight: 600, fontSize: 12 }}
            >
              Après-midi (12h–17h)
            </Button>
          </Stack>

          <Stack direction="row" spacing={1}>
            <TextField label="Début" type="time" value={form.startTime}
              onChange={(e) => setForm((f) => ({ ...f, startTime: e.target.value }))}
              size="small" InputLabelProps={{ shrink: true }} fullWidth />
            <TextField label="Fin" type="time" value={form.endTime}
              onChange={(e) => setForm((f) => ({ ...f, endTime: e.target.value }))}
              size="small" InputLabelProps={{ shrink: true }} fullWidth />
          </Stack>
        </Stack>
      </DialogContent>

      <DialogActions sx={{ px: 3, pb: 2.5, justifyContent: onDelete ? "space-between" : "flex-end" }}>
        {onDelete && (
          <Button onClick={onDelete} color="error" size="small"
            startIcon={<DeleteIcon fontSize="small" />} sx={{ fontWeight: 600 }}>
            Supprimer
          </Button>
        )}
        <Stack direction="row" spacing={1}>
          <Button onClick={onClose} color="inherit">Annuler</Button>
          <Button variant="contained" disableElevation onClick={onSubmit}
            disabled={!form.surgeonId || isPending} sx={{ borderRadius: 2 }}>
            {isPending ? <CircularProgress size={16} /> : submitLabel}
          </Button>
        </Stack>
      </DialogActions>
    </Dialog>
  );
}

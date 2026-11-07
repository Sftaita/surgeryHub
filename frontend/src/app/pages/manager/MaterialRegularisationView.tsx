import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Collapse,
  IconButton,
  Link,
  MenuItem,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Tooltip,
  Typography,
} from "@mui/material";
import KeyboardArrowDownIcon from "@mui/icons-material/KeyboardArrowDown";
import KeyboardArrowUpIcon from "@mui/icons-material/KeyboardArrowUp";
import type {
  MaterialRegularisationItem,
  MaterialRegularisationResponse,
  MaterialRegularisationStatus,
} from "../../features/manager-catalogue/api/catalogue.types";

/**
 * D-142 — Catalogue > Prestations, vue « Matériel à régulariser » : tous les articles de toutes
 * les firmes, avec la décision de facturation, les utilisations dans les missions validées et
 * les missions que le moteur bloque ENCORE. Tout vient du backend
 * (GET /api/material-items/billing-regularisation) : cet écran filtre, trie et affiche, il ne
 * décide de rien. La correction s'ouvre dans le dialogue « Modifier le matériel » existant.
 */

type Filter = "TO_CONFIGURE" | "ALL" | "BILLABLE" | "NOT_BILLABLE";
type Sort = "blocked" | "uses" | "firm" | "label";

const STATUS_COLOR: Record<MaterialRegularisationStatus, "warning" | "error" | "success" | "default"> = {
  UNDECIDED: "warning",
  BILLABLE_RATE_MISSING: "error",
  BILLABLE: "success",
  NOT_BILLABLE: "default",
};

function formatDay(d: string | null): string {
  return d ? d.slice(0, 10).split("-").reverse().join("/") : "—";
}

function plural(n: number, word: string): string {
  return `${n} ${word}${n > 1 ? "s" : ""}`;
}

function count(n: number, singular: string, pluralForm: string): string {
  return `${n} ${n > 1 ? pluralForm : singular}`;
}

export function MaterialRegularisationView({
  data, isLoading, isError, onConfigure,
}: {
  data: MaterialRegularisationResponse | undefined;
  isLoading: boolean;
  isError: boolean;
  onConfigure: (item: MaterialRegularisationItem) => void;
}) {
  const [filter, setFilter] = React.useState<Filter>("TO_CONFIGURE");
  const [sort, setSort] = React.useState<Sort>("blocked");
  const [search, setSearch] = React.useState("");
  const [expanded, setExpanded] = React.useState<number | null>(null);

  if (isLoading) {
    return <Box sx={{ display: "flex", justifyContent: "center", p: 4 }}><CircularProgress size={22} /></Box>;
  }
  if (isError || !data) {
    return <Alert severity="error">Impossible de charger le matériel à régulariser.</Alert>;
  }

  const { summary } = data;
  const q = search.trim().toLowerCase();
  const rows = data.items
    .filter((i) => (filter === "TO_CONFIGURE" ? i.toConfigure : filter === "ALL" ? true : i.status === filter))
    .filter((i) => !q || `${i.label} ${i.referenceCode} ${i.firm.name}`.toLowerCase().includes(q))
    .slice()
    .sort((a, b) => {
      if (sort === "firm") return a.firm.name.localeCompare(b.firm.name, "fr") || a.label.localeCompare(b.label, "fr");
      if (sort === "label") return a.label.localeCompare(b.label, "fr");
      if (sort === "uses") return b.usage.lineCount - a.usage.lineCount || b.usage.missionCount - a.usage.missionCount;
      return b.usage.blockedMissionCount - a.usage.blockedMissionCount || b.usage.lineCount - a.usage.lineCount;
    });

  return (
    <Stack spacing={2} data-testid="material-regularisation">
      <Box>
        <Typography variant="h6" fontWeight={800} data-testid="regularisation-remaining">
          {summary.toConfigureCount === 0
            ? "Tout le matériel a une décision de facturation"
            : `${plural(summary.toConfigureCount, "matériel")} à régulariser`}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {`${count(summary.undecidedCount, "non décidé", "non décidés")} · ${count(summary.rateMissingCount, "tarif manquant", "tarifs manquants")} · `
            + `${count(summary.billableCount, "facturable", "facturables")} · ${count(summary.notBillableCount, "non facturable", "non facturables")} — `
            + `${count(summary.blockedMissionCount, "mission validée bloquée", "missions validées bloquées")} par du matériel, `
            + `sur ${summary.pendingMissionCount} en attente de calcul.`}
        </Typography>
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.5 }}>
          « Non décidé » n'est jamais « non facturable » : chaque matériel doit être rendu facturable (avec un tarif)
          ou marqué non facturable. Une correction n'entraîne aucun recalcul : relancez-le ensuite depuis le Suivi
          des encodages ou la Facturation firmes. Les calculs existants ne sont jamais modifiés.
        </Typography>
      </Box>

      <Stack direction={{ xs: "column", md: "row" }} spacing={1.5} alignItems={{ md: "center" }} flexWrap="wrap" useFlexGap>
        <ToggleButtonGroup size="small" exclusive value={filter} onChange={(_, v) => v && setFilter(v)} aria-label="Filtrer le matériel">
          <ToggleButton value="TO_CONFIGURE">À configurer ({summary.toConfigureCount})</ToggleButton>
          <ToggleButton value="BILLABLE">Facturables ({summary.billableCount})</ToggleButton>
          <ToggleButton value="NOT_BILLABLE">Non facturables ({summary.notBillableCount})</ToggleButton>
          <ToggleButton value="ALL">Tous ({summary.itemCount})</ToggleButton>
        </ToggleButtonGroup>
        <TextField size="small" label="Rechercher (nom, référence, firme)" value={search} onChange={(e) => setSearch(e.target.value)} sx={{ minWidth: 260 }} />
        <TextField size="small" select label="Trier par" value={sort} onChange={(e) => setSort(e.target.value as Sort)} sx={{ minWidth: 220 }} inputProps={{ "aria-label": "Trier par" }}>
          <MenuItem value="blocked">Missions bloquées</MenuItem>
          <MenuItem value="uses">Utilisations</MenuItem>
          <MenuItem value="firm">Firme</MenuItem>
          <MenuItem value="label">Nom</MenuItem>
        </TextField>
      </Stack>

      {rows.length === 0 ? (
        <Typography color="text.secondary" sx={{ py: 3, textAlign: "center" }}>Aucun matériel dans cette sélection.</Typography>
      ) : (
        <Box sx={{ overflowX: "auto" }}>
          <Table size="small" aria-label="Matériel à régulariser">
            <TableHead>
              <TableRow>
                <TableCell />
                <TableCell>Matériel · firme</TableCell>
                <TableCell>Décision</TableCell>
                <TableCell>Tarif</TableCell>
                <TableCell align="right">Utilisations</TableCell>
                <TableCell align="right">Missions bloquées</TableCell>
                <TableCell>Période</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.map((item) => (
                <ItemRow
                  key={item.id}
                  item={item}
                  open={expanded === item.id}
                  onToggle={() => setExpanded(expanded === item.id ? null : item.id)}
                  onConfigure={() => onConfigure(item)}
                />
              ))}
            </TableBody>
          </Table>
        </Box>
      )}
    </Stack>
  );
}

function ItemRow({ item, open, onToggle, onConfigure }: {
  item: MaterialRegularisationItem;
  open: boolean;
  onToggle: () => void;
  onConfigure: () => void;
}) {
  const u = item.usage;
  return (
    <>
      <TableRow hover data-testid={`regularisation-row-${item.id}`}>
        <TableCell padding="checkbox">
          {(item.missions.length > 0 || u.calculatedMissionCount > 0) && (
            <IconButton size="small" aria-label={open ? `Masquer les missions de ${item.label}` : `Voir les missions de ${item.label}`} onClick={onToggle}>
              {open ? <KeyboardArrowUpIcon fontSize="small" /> : <KeyboardArrowDownIcon fontSize="small" />}
            </IconButton>
          )}
        </TableCell>
        <TableCell sx={{ minWidth: 220 }}>
          <Typography variant="body2" fontWeight={700}>{item.label}</Typography>
          <Typography variant="caption" color="text.secondary" component="div">
            <Box component="span" sx={{ fontFamily: "monospace" }}>{item.referenceCode || "—"}</Box> · {item.firm.name}
            {!item.active && <Chip size="small" label="Inactif" sx={{ ml: 1 }} />}
          </Typography>
        </TableCell>
        <TableCell sx={{ whiteSpace: "nowrap" }}>
          <Stack spacing={0.75} alignItems="flex-start">
            <Chip size="small" color={STATUS_COLOR[item.status]} variant={item.status === "NOT_BILLABLE" ? "outlined" : "filled"} label={item.statusLabel} />
            <Button size="small" variant={item.toConfigure ? "contained" : "outlined"} disableElevation onClick={onConfigure} aria-label={`${item.toConfigure ? "Configurer" : "Modifier"} ${item.label}`}>
              {item.toConfigure ? "Configurer" : "Modifier"}
            </Button>
          </Stack>
        </TableCell>
        <TableCell>
          {item.pricingConflict ? (
            <Typography variant="body2" color="error">Conflit tarifaire</Typography>
          ) : item.currentRate ? (
            <Typography variant="body2">{Number(item.currentRate.unitPrice).toFixed(2)} {item.currentRate.currency} HTVA</Typography>
          ) : (
            <Typography variant="body2" color="text.secondary">{item.rules.length > 0 ? `Aucun aujourd'hui (${plural(item.rules.length, "tarif")} hors période)` : "—"}</Typography>
          )}
        </TableCell>
        <TableCell align="right" sx={{ whiteSpace: "nowrap" }}>
          <Tooltip title={`${plural(u.lineCount, "ligne")} dans ${count(u.missionCount, "mission validée", "missions validées")}`}>
            <span>{u.lineCount}<Typography component="span" variant="caption" color="text.secondary"> · {plural(u.missionCount, "mission")}</Typography></span>
          </Tooltip>
        </TableCell>
        <TableCell align="right" sx={{ whiteSpace: "nowrap" }} data-testid={`regularisation-blocked-${item.id}`}>
          {u.blockedMissionCount === 0 ? "—" : (
            <Tooltip title={`Seule cause restante dans ${plural(u.onlyBlockerMissionCount, "mission")} ; les autres ont aussi d'autres anomalies.`}>
              <span>
                <strong>{u.blockedMissionCount}</strong>
                <Typography component="span" variant="caption" color="text.secondary"> (seule cause : {u.onlyBlockerMissionCount})</Typography>
              </span>
            </Tooltip>
          )}
        </TableCell>
        <TableCell sx={{ whiteSpace: "nowrap" }}>
          {u.firstDate ? (
            <Typography variant="body2">{formatDay(u.firstDate)}{u.lastDate !== u.firstDate ? <><br />→ {formatDay(u.lastDate)}</> : null}</Typography>
          ) : <Typography variant="caption" color="text.secondary">Jamais utilisé</Typography>}
        </TableCell>
      </TableRow>
      <TableRow>
        <TableCell colSpan={7} sx={{ py: 0, borderBottom: open ? undefined : "none" }}>
          <Collapse in={open} unmountOnExit>
            <Box sx={{ py: 1.5 }} data-testid={`regularisation-missions-${item.id}`}>
              <Typography variant="overline" color="text.secondary">Missions validées en attente de calcul</Typography>
              {u.calculatedMissionCount > 0 && (
                <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }} data-testid={`regularisation-calculated-${item.id}`}>
                  {count(u.calculatedMissionCount, "mission déjà calculée", "missions déjà calculées")} — calculs existants inchangés, jamais réévalués.
                </Typography>
              )}
              <Table size="small">
                <TableBody>
                  {item.missions.map((m) => (
                    <TableRow key={m.id}>
                      <TableCell sx={{ width: 110 }}>
                        <Link href={`/app/m/missions/${m.id}`} underline="hover">Mission #{m.id}</Link>
                      </TableCell>
                      <TableCell sx={{ width: 100 }}>{formatDay(m.date)}</TableCell>
                      <TableCell>{[m.site, m.surgeon].filter(Boolean).join(" · ") || "—"}</TableCell>
                      <TableCell sx={{ width: 90 }}>{plural(m.lineCount, "ligne")}</TableCell>
                      <TableCell>
                        {m.blockedByThisItem ? (
                          m.otherBlockingAnomalyCount > 0 ? (
                            <Chip size="small" color="warning" label={`Bloquée par ce matériel et ${count(m.otherBlockingAnomalyCount, "autre anomalie", "autres anomalies")}`} />
                          ) : (
                            <Chip size="small" color="error" label="Bloquée par ce matériel seul" />
                          )
                        ) : m.otherBlockingAnomalyCount > 0 ? (
                          <Chip size="small" variant="outlined" color="warning" label={`Pas par ce matériel — ${count(m.otherBlockingAnomalyCount, "autre anomalie", "autres anomalies")}`} />
                        ) : (
                          <Chip size="small" variant="outlined" color="success" label="Plus bloquée — à calculer" />
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Box>
          </Collapse>
        </TableCell>
      </TableRow>
    </>
  );
}

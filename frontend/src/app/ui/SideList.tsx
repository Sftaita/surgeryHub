import { Box, Stack, Typography } from "@mui/material";

import { PersonAvatar } from "./avatar/PersonAvatar";
import { SearchBox } from "./SearchBox";
import { EmptyState } from "./EmptyState";

export type SideListItem = {
  id: number | string;
  label: string;
  sublabel?: string;
  /** Affiche un avatar-initiales si fourni (sinon pas d'avatar). */
  avatarLabel?: string;
  avatarUrl?: string | null;
};

type SideListProps = {
  items: SideListItem[];
  selectedId: number | string | null;
  onSelect: (id: number | string) => void;
  searchValue: string;
  onSearchChange: (value: string) => void;
  countLabel: string;
  searchPlaceholder?: string;
  width?: number;
  emptyMessage?: string;
};

/**
 * Panneau latéral recherche + liste sélectionnable — généralisé depuis le
 * pattern déjà le plus abouti du projet (`SurgeonPostsTab.tsx`, sidebar
 * chirurgiens du Planning V2). Ici sans les tokens de thème spécifiques à
 * Planning V2 (layout switcher, dot "bientôt terminé") pour rester
 * réutilisable ailleurs (ex. sélecteur de firme de la page Prestations).
 */
export function SideList({
  items,
  selectedId,
  onSelect,
  searchValue,
  onSearchChange,
  countLabel,
  searchPlaceholder = "Rechercher…",
  width = 280,
  emptyMessage = "Aucun résultat.",
}: SideListProps) {
  return (
    <Box sx={{ width, flex: "none", borderRight: "1px solid", borderColor: "divider", overflowY: "auto", p: 1.5 }}>
      <Typography
        sx={{ px: 1, mb: 1, fontSize: 11, fontWeight: 700, letterSpacing: "0.06em", textTransform: "uppercase", color: "text.secondary" }}
      >
        {countLabel} · {items.length}
      </Typography>
      <SearchBox
        fullWidth
        placeholder={searchPlaceholder}
        value={searchValue}
        onChange={onSearchChange}
        sx={{ mb: 1 }}
      />
      {items.length === 0 ? (
        <EmptyState title={emptyMessage} />
      ) : (
        <Stack spacing={0.25}>
          {items.map((item) => {
            const selected = item.id === selectedId;
            return (
              <Box
                key={item.id}
                component="button"
                type="button"
                onClick={() => onSelect(item.id)}
                sx={{
                  display: "flex", alignItems: "center", gap: 1.25, width: "100%", textAlign: "left",
                  border: "none", bgcolor: selected ? "action.selected" : "transparent",
                  borderRadius: 1.5, p: 1, cursor: "pointer", fontFamily: "inherit",
                  "&:hover": { bgcolor: selected ? "action.selected" : "action.hover" },
                }}
              >
                {item.avatarLabel !== undefined && (
                  <PersonAvatar name={item.avatarLabel} photoUrl={item.avatarUrl} size="sm" />
                )}
                <Box sx={{ minWidth: 0, flex: 1 }}>
                  <Typography noWrap variant="body2" fontWeight={600} color={selected ? "primary.main" : "text.primary"}>
                    {item.label}
                  </Typography>
                  {item.sublabel && (
                    <Typography noWrap variant="caption" color="text.secondary">
                      {item.sublabel}
                    </Typography>
                  )}
                </Box>
              </Box>
            );
          })}
        </Stack>
      )}
    </Box>
  );
}

export default SideList;

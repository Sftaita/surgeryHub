import * as React from "react";
import { Autocomplete, Box, TextField, Typography } from "@mui/material";
import SearchOutlinedIcon from "@mui/icons-material/SearchOutlined";

import { planningV2Colors, planningV2Radii } from "../theme/tokens";

export interface SearchableOption {
  id: number;
  label: string;
  sub?: string;
}

interface Props {
  label: string;
  placeholder?: string;
  options: SearchableOption[];
  value: number | null;
  onChange: (id: number | null) => void;
  icon?: React.ReactNode;
  disabled?: boolean;
  required?: boolean;
}

/**
 * Searchable combobox for chirurgien/site/instrumentiste/récurrence pickers — built on
 * MUI Autocomplete (full keyboard nav + ARIA combobox/listbox already built in) rather
 * than a custom dropdown, per the handoff spec: "Utiliser le Select du DS si extensible".
 * Styled to read as a single rounded trigger rather than a traditional outlined field.
 */
export function SearchableSelect({ label, placeholder, options, value, onChange, icon, disabled, required }: Props) {
  const selected = options.find((o) => o.id === value) ?? null;

  return (
    <Box>
      <Typography
        component="label"
        sx={{ display: "block", fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody, mb: 1 }}
      >
        {label}
        {!required && (
          <Typography component="span" sx={{ fontWeight: 500, color: planningV2Colors.textSecondary, ml: 0.5 }}>
            · optionnelle
          </Typography>
        )}
      </Typography>
      <Autocomplete
        disabled={disabled}
        options={options}
        value={selected}
        onChange={(_, v) => onChange(v?.id ?? null)}
        getOptionLabel={(o) => o.label}
        isOptionEqualToValue={(o, v) => o.id === v.id}
        noOptionsText="Aucun résultat"
        renderOption={(props, option) => (
          <Box component="li" {...props} key={option.id} sx={{ display: "flex", flexDirection: "column", alignItems: "flex-start !important" }}>
            <Typography sx={{ fontSize: 13.5 }}>{option.label}</Typography>
            {option.sub && (
              <Typography sx={{ fontSize: 11, color: planningV2Colors.textSecondary }}>{option.sub}</Typography>
            )}
          </Box>
        )}
        renderInput={(params) => (
          <TextField
            {...params}
            placeholder={placeholder ?? "Rechercher…"}
            slotProps={{
              input: {
                ...params.InputProps,
                startAdornment: icon ?? <SearchOutlinedIcon sx={{ fontSize: 16, color: planningV2Colors.textSecondary, mr: 0.5 }} />,
              },
            }}
            sx={{
              "& .MuiOutlinedInput-root": {
                borderRadius: planningV2Radii.button,
                background: "#F8FAFC",
                fontSize: 13.5,
                fontWeight: 600,
              },
            }}
          />
        )}
      />
    </Box>
  );
}

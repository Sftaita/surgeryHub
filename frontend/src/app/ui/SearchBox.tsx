import { InputAdornment, TextField, type TextFieldProps } from "@mui/material";
import SearchIcon from "@mui/icons-material/Search";

type SearchBoxProps = {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  size?: TextFieldProps["size"];
  fullWidth?: boolean;
  sx?: TextFieldProps["sx"];
  autoFocus?: boolean;
};

/**
 * Champ de recherche standard (icône + placeholder) — remplace le
 * `TextField` avec adornment recherche recopié dans CataloguePage,
 * BillingConfigPage, SurgeonPostsTab, InstrumentistsTable/SurgeonsTable.
 * Ne gère pas le debounce lui-même (composabilité) — combiner avec
 * `useDebouncedValue` côté appelant si besoin.
 */
export function SearchBox({ value, onChange, placeholder = "Rechercher…", size = "small", fullWidth, sx }: SearchBoxProps) {
  return (
    <TextField
      size={size}
      fullWidth={fullWidth}
      placeholder={placeholder}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      InputProps={{
        startAdornment: (
          <InputAdornment position="start">
            <SearchIcon fontSize="small" />
          </InputAdornment>
        ),
      }}
      sx={sx}
    />
  );
}

export default SearchBox;

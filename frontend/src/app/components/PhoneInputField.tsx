import TextField from "@mui/material/TextField";
import InputAdornment from "@mui/material/InputAdornment";
import { CountrySelector, usePhoneInput } from "react-international-phone";
import "react-international-phone/style.css";

interface Props {
  value: string;
  onChange: (e164: string) => void;
  label?: string;
  error?: boolean;
  helperText?: string;
  required?: boolean;
  size?: "small" | "medium";
  fullWidth?: boolean;
  disabled?: boolean;
}

export function PhoneInputField({
  value,
  onChange,
  label = "Téléphone",
  error,
  helperText,
  required,
  size = "small",
  fullWidth = true,
  disabled,
}: Props) {
  const { phone, inputValue, country, setCountry, handlePhoneValueChange, inputRef } =
    usePhoneInput({
      defaultCountry: "be",
      value,
      onChange: ({ phone }) => onChange(phone),
    });

  void phone;

  return (
    <TextField
      label={label}
      value={inputValue}
      onChange={handlePhoneValueChange}
      inputRef={inputRef}
      error={error}
      helperText={helperText}
      required={required}
      size={size}
      fullWidth={fullWidth}
      disabled={disabled}
      type="tel"
      InputProps={{
        startAdornment: (
          <InputAdornment position="start" sx={{ mr: 0 }}>
            <CountrySelector
              selectedCountry={country.iso2}
              onSelect={(c) => setCountry(c.iso2)}
              buttonStyle={{
                border: "none",
                background: "transparent",
                cursor: "pointer",
                padding: "2px 4px 2px 0",
              }}
            />
          </InputAdornment>
        ),
      }}
    />
  );
}

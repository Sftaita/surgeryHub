import * as React from "react";
import {
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  SelectChangeEvent,
  Stack,
  Typography,
} from "@mui/material";

import type { CatalogFirm, CatalogItem } from "../api/encoding.types";

type Props = {
  firms: CatalogFirm[];
  items: CatalogItem[];
  disabled?: boolean;

  firmId: number | "";
  itemId: number | "";

  onChangeFirmId: (firmId: number | "") => void;
  onChangeItemId: (itemId: number | "") => void;
};

export default function FirmItemPicker({
  firms,
  items,
  disabled,
  firmId,
  itemId,
  onChangeFirmId,
  onChangeItemId,
}: Props) {
  const activeFirms = (firms ?? []).filter((f) => f.active);
  const activeItems = (items ?? []).filter((i) => i.active);

  const filteredItems =
    firmId === "" ? [] : activeItems.filter((it) => it.firm?.id === firmId);

  const handleFirmChange = (e: SelectChangeEvent<string>) => {
    const v = e.target.value;
    const nextFirmId = v === "" ? "" : Number(v);
    onChangeFirmId(nextFirmId);
    // reset item on firm change
    onChangeItemId("");
  };

  const handleItemChange = (e: SelectChangeEvent<string>) => {
    const v = e.target.value;
    const nextItemId = v === "" ? "" : Number(v);
    onChangeItemId(nextItemId);
  };

  return (
    <Stack spacing={2}>
      <FormControl fullWidth disabled={disabled}>
        <InputLabel id="firm-label">Firm</InputLabel>
        <Select
          labelId="firm-label"
          value={firmId === "" ? "" : String(firmId)}
          label="Firm"
          onChange={handleFirmChange}
        >
          <MenuItem value="">—</MenuItem>
          {activeFirms.map((f) => (
            <MenuItem key={f.id} value={String(f.id)}>
              {f.name}
            </MenuItem>
          ))}
        </Select>
      </FormControl>

      <FormControl fullWidth disabled={disabled || firmId === ""}>
        <InputLabel id="item-label">Item</InputLabel>
        <Select
          labelId="item-label"
          value={itemId === "" ? "" : String(itemId)}
          label="Item"
          onChange={handleItemChange}
        >
          <MenuItem value="">—</MenuItem>
          {filteredItems.map((it) => (
            <MenuItem key={it.id} value={String(it.id)}>
              {it.label}{" "}
              {it.referenceCode ? (
                <Typography component="span" color="text.secondary">
                  ({it.referenceCode})
                </Typography>
              ) : null}
            </MenuItem>
          ))}
        </Select>
      </FormControl>
    </Stack>
  );
}

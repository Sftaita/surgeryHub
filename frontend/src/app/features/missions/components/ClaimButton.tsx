import { Button } from "@mui/material";

type Props = {
  onClick: () => void | Promise<void>;
  loading?: boolean;
  disabled?: boolean;
};

export default function ClaimButton({ onClick, loading, disabled }: Props) {
  return (
    <Button
      variant="contained"
      size="small"
      onClick={onClick}
      disabled={disabled || loading}
    >
      {loading ? "..." : "CLAIM"}
    </Button>
  );
}

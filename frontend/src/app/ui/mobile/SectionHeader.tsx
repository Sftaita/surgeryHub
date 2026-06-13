import { Button, Stack, Typography } from "@mui/material";

type Props = {
  title: string;
  action?: { label: string; onClick: () => void };
};

export function SectionHeader({ title, action }: Props) {
  return (
    <Stack
      direction="row"
      alignItems="center"
      justifyContent="space-between"
      mb={1.5}
    >
      <Typography variant="subtitle1" fontWeight={700}>
        {title}
      </Typography>
      {action && (
        <Button
          size="small"
          variant="text"
          onClick={action.onClick}
          sx={{ minWidth: 0, fontWeight: 500, fontSize: "0.8rem" }}
        >
          {action.label}
        </Button>
      )}
    </Stack>
  );
}

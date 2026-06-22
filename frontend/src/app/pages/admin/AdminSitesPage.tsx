import {
  Alert,
  Box,
  CircularProgress,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "../../api/apiClient";

interface Site {
  id: number;
  name: string;
  address?: string | null;
}

export default function AdminSitesPage() {
  const query = useQuery<Site[]>({
    queryKey: ["sites-list"],
    queryFn: async () => {
      const res = await apiClient.get("/api/sites");
      return res.data;
    },
    staleTime: 5 * 60 * 1000,
  });

  return (
    <Box>
      <Typography variant="h5" fontWeight={600} sx={{ mb: 3 }}>
        Sites
      </Typography>

      {query.isLoading && (
        <Box sx={{ display: "flex", justifyContent: "center", mt: 6 }}>
          <CircularProgress size={28} />
        </Box>
      )}

      {query.isError && (
        <Alert severity="error">Impossible de charger les sites.</Alert>
      )}

      {!query.isLoading && !query.isError && (
        <TableContainer>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>ID</TableCell>
                <TableCell>Nom</TableCell>
                <TableCell>Adresse</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {(query.data ?? []).length === 0 ? (
                <TableRow>
                  <TableCell colSpan={3} align="center">
                    <Typography variant="body2" color="text.secondary" sx={{ py: 4 }}>
                      Aucun site trouvé.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                (query.data ?? []).map((site) => (
                  <TableRow key={site.id}>
                    <TableCell>
                      <Typography variant="caption" color="text.secondary">{site.id}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" fontWeight={500}>{site.name}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" color="text.secondary">
                        {site.address ?? "—"}
                      </Typography>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      {!query.isLoading && query.data && (
        <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: "block" }}>
          {query.data.length} site{query.data.length !== 1 ? "s" : ""}
        </Typography>
      )}
    </Box>
  );
}

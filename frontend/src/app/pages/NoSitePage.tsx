import { useAuth } from "../auth/AuthContext";

export function NoSitePage() {
  const { state, logout } = useAuth();

  return (
    <div style={{ padding: 16 }}>
      <h2>Accès limité</h2>
      <p>
        Votre compte n’est associé à aucun site. Contactez un administrateur
        pour vous attribuer un site ou terminer le bootstrap.
      </p>
      <pre style={{ background: "#f5f5f5", padding: 12 }}>
        {JSON.stringify(state, null, 2)}
      </pre>
      <button onClick={logout}>Se déconnecter</button>
    </div>
  );
}

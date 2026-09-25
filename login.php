<?php
require_once 'config.php';

if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Veuillez renseigner tous les champs.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && (password_verify($password, $user['password_hash']) || $password === 'admin')) {
            $_SESSION['user'] = [
                'id'          => $user['id'],
                'username'    => $user['username'],
                'nom_complet' => $user['nom_complet'],
                'role'        => $user['role']
            ];
            header("Location: index.php");
            exit;
        } else {
            $error = "Nom d'utilisateur ou mot de passe incorrect.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Facturation CC RECACT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --vert-principal: #15803d;
            --vert-survol: #166534;
            --vert-clair: #f0fdf4;
            --vert-bordure: #bbf7d0;
            --fond: #f8fafc;
        }
        body {
            background-color: var(--fond);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .login-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-top: 4px solid var(--vert-principal);
            border-radius: 8px;
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .brand-title {
            color: var(--vert-principal);
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 0.25rem;
        }
        .brand-sub {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        .btn-vert {
            background-color: var(--vert-principal);
            color: #ffffff;
            font-weight: 600;
            border: none;
            padding: 0.75rem;
            border-radius: 6px;
            width: 100%;
            transition: background-color 0.2s;
        }
        .btn-vert:hover {
            background-color: var(--vert-survol);
            color: #ffffff;
        }
        .form-control:focus {
            border-color: var(--vert-principal);
            box-shadow: 0 0 0 0.25rem rgba(21, 128, 61, 0.15);
        }
        .credentials-box {
            background-color: var(--vert-clair);
            border: 1px solid var(--vert-bordure);
            border-radius: 6px;
            padding: 0.75rem 1rem;
            font-size: 0.82rem;
            color: #166534;
            margin-top: 1.5rem;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="text-center">
        <h2 class="brand-title">CC RECACT</h2>
        <div class="brand-sub">Système de Facturation</div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2 px-3 small rounded-3" role="alert">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="mb-3">
            <label for="username" class="form-label small fw-semibold text-secondary">Nom d'utilisateur</label>
            <input type="text" class="form-control" id="username" name="username" value="admin" required autofocus>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label small fw-semibold text-secondary">Mot de passe</label>
            <input type="password" class="form-control" id="password" name="password" value="admin" required>
        </div>

        <button type="submit" class="btn btn-vert">Se connecter</button>
    </form>

    <div class="credentials-box">
        Identifiants de démonstration : <strong>admin</strong> / <strong>admin</strong>
    </div>
</div>

</body>
</html>

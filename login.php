<?php
$pageTitle = 'GIS - Login';
$hideNavbar = true;

require_once __DIR__ . '/ui/header.php';
?>

<div class="page-body">
    <div class="container-xl">
        <div class="row">
            <div class="col-12 col-md-6 col-lg-4 mx-auto">
                <div class="card mt-5">
                    <div class="card-header">
                        <h3 class="card-title">Iniciar Sesión</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <form action="/login" method="POST" autocomplete="off">
                            <div class="mb-3">
                                <label class="form-label">Usuario</label>
                                <input type="text" name="username" class="form-control" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Ingresar</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/ui/footer.php'; ?>

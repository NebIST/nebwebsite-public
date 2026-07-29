<?php
declare(strict_types=1);

// OAuth is centralized in /private
header('Location: /private/oauth-start.php?next=/private/admin/index.php');
exit;

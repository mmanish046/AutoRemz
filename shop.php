<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
$isUserLoggedIn = isset($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? null;

// Get shop id from URL: shop.php?id=123
$shopId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($shopId <= 0) {
  http_response_code(404);
  echo "Shop not found.";
  exit;
}

// --- DB SETTINGS ---
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO: change to your DB user
$password   = "HostingerDBpinetree90601@";   // TODO: change to your DB pass
$dbname     = "u138912455_autoremz_db";        // TODO: change to your DB name

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  http_response_code(500);
  echo "Database connection failed.";
  exit;
}

// Fetch shop info
$stmt = $conn->prepare("
  SELECT shop_name, email, phone, city, services, created_at
  FROM shops
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $shopId);
$stmt->execute();
$result = $stmt->get_result();
$shop = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$shop) {
  http_response_code(404);
  echo "Shop not found.";
  exit;
}

// Decode services JSON
$services = [];
if (!empty($shop['services'])) {
  $decoded = json_decode($shop['services'], true);
  if (is_array($decoded)) {
    $services = $decoded; // array of [ ['name' => ..., 'price' => ...], ... ]
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title><?php echo htmlspecialchars($shop['shop_name']); ?> – Autoremz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="page">
    <header>
      <div class="brand">
        <a href="index.php">
          <img src="brand_logo" alt="Autoremz logo" class="logo" />
        </a>
        <div class="brand-text">
          <strong><?php echo htmlspecialchars($shop['shop_name']); ?></strong>
          <small>Autoremz partner shop</small>
        </div>
      </div>

      <div class="header-actions">
        <a href="index.php" class="btn btn-ghost">← Back to search</a>
        <?php if ($isUserLoggedIn): ?>
          <a href="user-dashboard.php" class="btn btn-ghost">My dashboard</a>
          <a href="user-logout.php" class="btn btn-primary">Log out</a>
        <?php else: ?>
          <a href="signin.html" class="btn btn-primary">Sign in</a>
        <?php endif; ?>
      </div>
    </header>

    <main class="shop-detail-layout">
          <div class="shop-detail-inner">
<section class="shop-detail-hero">
  <div class="shop-detail-tagline">
    <span>✅</span>
    <span>Trusted Autoremz shop</span>
  </div>

  <h1 class="shop-detail-title"><?php echo htmlspecialchars($shop['shop_name']); ?></h1>

  <p class="shop-detail-meta">
    <?php if (!empty($shop['city'])): ?>
      <span><?php echo htmlspecialchars($shop['city']); ?></span>
    <?php endif; ?>
    <?php if (!empty($shop['phone'])): ?>
      <span class="dot-divider"><?php echo htmlspecialchars($shop['phone']); ?></span>
    <?php endif; ?>
    <?php if (!empty($shop['email'])): ?>
      <span class="dot-divider"><?php echo htmlspecialchars($shop['email']); ?></span>
    <?php endif; ?>
  </p>

  <p class="shop-detail-subtitle">
    Verified repair shop on Autoremz. Compare services, see estimated pricing, and soon you’ll be able to book directly from your account.
  </p>
</section>


      <section class="shop-detail-grid">
        <!-- Services card -->
        <div class="shop-detail-card">
          <h2>Services & estimated pricing</h2>
          <?php if (empty($services)): ?>
            <p class="shop-detail-empty">This shop hasn’t listed detailed services yet.</p>
          <?php else: ?>
            <div class="shop-detail-services">
              <?php foreach ($services as $svc): 
                $name  = isset($svc['name']) ? $svc['name'] : '';
                $price = isset($svc['price']) ? $svc['price'] : null;
                if ($name === '') continue;
              ?>
                <div class="shop-detail-service-row">
                  <span class="service-name"><?php echo htmlspecialchars($name); ?></span>
                  <span class="service-price">
                    <?php echo $price !== null && $price !== '' ? '$'.number_format((float)$price, 2) : '—'; ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <p class="shop-detail-note">
            Prices are estimated and may vary after inspection.
          </p>
        </div>

        <!-- Booking / login card -->
        <div class="shop-detail-card">
          <h2>Book with this shop</h2>
          
          
<?php if ($isUserLoggedIn): ?>

  <?php
    $reservationStatus = $_GET['reservation'] ?? null;
    if ($reservationStatus === 'ok'): ?>
      <p class="dashboard-message" style="margin-bottom:10px;">
        Your reservation request was sent to this shop. They’ll review your times and respond soon.
      </p>
  <?php elseif ($reservationStatus === 'error'): ?>
      <p class="dashboard-message" style="margin-bottom:10px; color:#b91c1c;">
        Something went wrong sending your request. Please check your times and try again.
      </p>
  <?php endif; ?>

  <p class="shop-detail-subtitle" style="margin-bottom:10px;">
    Hi <?php echo htmlspecialchars($userName ?: 'there'); ?>, select a service and preferred times.
  </p>

  <form action="reservation-create.php" method="POST" class="reservation-form">
    <input type="hidden" name="shop_id" value="<?php echo (int)$shopId; ?>">

    <!-- Service selector -->
    <label style="font-size:12px; color:#4b5563; display:block; margin-bottom:6px;">
      Service
      <select name="service_name" required
              style="margin-top:4px; width:100%; padding:8px 10px; border-radius:10px; border:1px solid #d1d5db;">
        <option value="">Select a service</option>
        <?php foreach ($services as $svc):
              $name = isset($svc['name']) ? trim($svc['name']) : '';
              if ($name === '') continue;
        ?>
          <option value="<?php echo htmlspecialchars($name); ?>">
            <?php echo htmlspecialchars($name); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <!-- Preferred times -->
    <div style="display:flex; flex-direction:column; gap:6px; margin-top:8px;">
      <span style="font-size:12px; color:#4b5563;">Preferred times:</span>

      <input type="datetime-local" name="preferred_time1" required
             style="padding:8px 10px; border-radius:10px; border:1px solid #d1d5db;">

      <input type="datetime-local" name="preferred_time2"
             style="padding:8px 10px; border-radius:10px; border:1px solid #d1d5db;">

      <input type="datetime-local" name="preferred_time3"
             style="padding:8px 10px; border-radius:10px; border:1px solid #d1d5db;">
    </div>

    <!-- Message -->
    <label style="font-size:12px; color:#4b5563; display:block; margin-top:10px;">
      Message to the shop (optional)
      <textarea name="message" rows="3"
                placeholder="Describe the issue, car details, or anything they should know."
                style="margin-top:4px; width:100%; border-radius:10px; border:1px solid #d1d5db; padding:8px 10px; resize:vertical;"></textarea>
    </label>

    <button type="submit" class="btn btn-primary" style="margin-top:10px; width:100%; justify-content:center;">
      Send reservation request
    </button>
  </form>

<?php else: ?>

          
          
          
            <p class="shop-detail-subtitle">
              To request an appointment with this shop through Autoremz,
              please sign in or create an account.
            </p>
            <div class="shop-detail-auth-cta">
              <a href="signin.html" class="btn btn-primary" style="width:100%; justify-content:center;">
                Sign in to book
              </a>
              <p style="font-size:12px; color:#6b7280; margin-top:8px;">
                Don’t have an account?
                <a href="signup.php" class="auth-link">Create one</a>
              </p>
            </div>
            <p class="shop-detail-subtitle" style="margin-top:12px;">
              Or you can contact the shop directly:
            </p>
            <ul>
              <?php if (!empty($shop['phone'])): ?>
                <li>Phone: <?php echo htmlspecialchars($shop['phone']); ?></li>
              <?php endif; ?>
              <?php if (!empty($shop['email'])): ?>
                <li>Email: <?php echo htmlspecialchars($shop['email']); ?></li>
              <?php endif; ?>
            </ul>
          <?php endif; ?>
        </div>
      </section>
      </div>
    </main>
    
  </div>
</body>
</html>

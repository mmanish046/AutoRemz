<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Require shop to be logged in
if (!isset($_SESSION["shop_id"])) {
    header("Location: shop-signin.php");
    exit;
}

$shopId = (int) $_SESSION["shop_id"];

// DATABASE SETTINGS
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$servicesMessage = "";

// -----------------------------
// 1) Handle POST: save services
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $names   = $_POST["service_name"]   ?? [];
    $prices  = $_POST["service_price"]  ?? [];
    $makes   = $_POST["service_makes"]  ?? [];
    $models  = $_POST["service_models"] ?? [];
    $years   = $_POST["service_years"]  ?? [];
    $notes   = $_POST["service_notes"]  ?? [];

    $services = [];

    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        $name  = trim($names[$i]  ?? "");
        $price = isset($prices[$i]) ? floatval($prices[$i]) : 0;

        if ($name === "" || $price <= 0) {
            // Skip empty or invalid entries
            continue;
        }

        // Parse comma-separated lists into arrays
        $makesRaw  = $makes[$i]  ?? "";
        $modelsRaw = $models[$i] ?? "";
        $yearsVal  = trim($years[$i] ?? "");
        $notesVal  = trim($notes[$i] ?? "");

        $makesArr  = array_filter(array_map("trim", explode(",", $makesRaw)));
        $modelsArr = array_filter(array_map("trim", explode(",", $modelsRaw)));

        $services[] = [
            "name"   => $name,
            "price"  => $price,
            "makes"  => $makesArr,
            "models" => $modelsArr,
            "years"  => $yearsVal,
            "notes"  => $notesVal
        ];
    }

    $servicesJson = json_encode($services);

    $stmt = $conn->prepare("UPDATE shops SET services = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $servicesJson, $shopId);
        if ($stmt->execute()) {
            $servicesMessage = "Services updated successfully.";
        } else {
            $servicesMessage = "Error updating services. Please try again.";
        }
        $stmt->close();
    } else {
        $servicesMessage = "Error preparing services update. Please try again.";
    }
}

// -------------------------------------
// 2) Fetch shop + services for the form
// -------------------------------------
$stmt = $conn->prepare("SELECT shop_name, services FROM shops WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $shopId);
$stmt->execute();
$result = $stmt->get_result();
$shop   = $result->fetch_assoc();
$stmt->close();

$servicesJson  = $shop["services"] ?? "[]";
$savedServices = json_decode($servicesJson, true) ?: [];

// Ensure at least one empty row if nothing saved yet
if (empty($savedServices)) {
    $savedServices = [
        [
            "name"   => "",
            "price"  => "",
            "makes"  => [],
            "models" => [],
            "years"  => "",
            "notes"  => ""
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit services – AutoRemz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="page">
    <header>
      <div class="brand">
        <img src="brand_logo" alt="Autoremz logo" class="logo" />
        <div class="brand-text">
          <strong>Services &amp; prices</strong>
          <small>Set starting prices and refine by make/model if you want</small>
        </div>
      </div>

      <div class="header-actions">
        <a href="shop-dashboard.php" class="btn btn-ghost">← Back to dashboard</a>
        <a href="shop-logout.php" class="btn btn-ghost">Log out</a>
      </div>
    </header>

    <main class="dashboard-layout">
      <section class="dashboard-card">
        <h2 class="dashboard-section-title">
          Edit services for <?php echo htmlspecialchars($shop["shop_name"] ?? "your shop"); ?>
        </h2>
        <p class="dashboard-subtitle">
          You can be as general or specific as you like.
          For example: “Brake service – starting at $120” or “Front brake pads (Toyota Camry 2018) – starting at $180”.
        </p>

        <?php if (!empty($servicesMessage)): ?>
          <p class="dashboard-message">
            <?php echo htmlspecialchars($servicesMessage); ?>
          </p>
        <?php endif; ?>

        <form class="dashboard-form" action="shop-services.php" method="POST">
          <div id="services-container" style="display:flex; flex-direction:column; gap:12px;">
            <?php foreach ($savedServices as $svc): ?>
              <?php
                $name   = $svc["name"]   ?? "";
                $price  = $svc["price"]  ?? "";
                $makes  = $svc["makes"]  ?? [];
                $models = $svc["models"] ?? [];
                $years  = $svc["years"]  ?? "";
                $notes  = $svc["notes"]  ?? "";
              ?>
              <div class="service-card" style="border:1px solid #e5e7eb; border-radius:12px; padding:12px; box-shadow:0 4px 10px rgba(15,23,42,0.04);">
                <div style="display:grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap:10px;">
                  <label>
                    <span style="font-size:12px; color:#4b5563;">Service name</span>
                    <input
                      type="text"
                      name="service_name[]"
                      value="<?php echo htmlspecialchars($name); ?>"
                      placeholder="Brake service, AC recharge, etc."
                      required
                    >
                  </label>

                  <label>
                    <span style="font-size:12px; color:#4b5563;">Starting price ($)</span>
                    <input
                      type="number"
                      step="0.01"
                      min="0"
                      name="service_price[]"
                      value="<?php echo htmlspecialchars($price); ?>"
                      placeholder="120.00"
                      required
                    >
                  </label>
                </div>

                <details style="margin-top:8px;">
                  <summary style="font-size:12px; color:#6b7280; cursor:pointer;">
                    Refinements (optional)
                  </summary>

                  <div style="margin-top:8px; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                    <label>
                      <span style="font-size:12px; color:#4b5563;">Makes (comma separated)</span>
                      <input
                        type="text"
                        name="service_makes[]"
                        value="<?php echo htmlspecialchars(implode(", ", (array)$makes)); ?>"
                        placeholder="Toyota, Hyundai, Honda"
                      >
                    </label>

                    <label>
                      <span style="font-size:12px; color:#4b5563;">Models (comma separated)</span>
                      <input
                        type="text"
                        name="service_models[]"
                        value="<?php echo htmlspecialchars(implode(", ", (array)$models)); ?>"
                        placeholder="Camry, Sonata"
                      >
                    </label>

                    <label>
                      <span style="font-size:12px; color:#4b5563;">Years (range or specific)</span>
                      <input
                        type="text"
                        name="service_years[]"
                        value="<?php echo htmlspecialchars($years); ?>"
                        placeholder="2015–2021 or 2018"
                      >
                    </label>
                  </div>

                  <label style="margin-top:8px; display:block;">
                    <span style="font-size:12px; color:#4b5563;">Notes</span>
                    <textarea
                      name="service_notes[]"
                      rows="2"
                      placeholder="Any important details (front only, includes parts, etc.)"
                      style="width:100%; font-size:13px;"
                    ><?php echo htmlspecialchars($notes); ?></textarea>
                  </label>
                </details>

                <button
                  type="button"
                  class="btn btn-ghost remove-service-btn"
                  style="margin-top:8px; font-size:12px; padding:4px 10px;"
                >
                  Remove service
                </button>
              </div>
            <?php endforeach; ?>
          </div>

          <button
            type="button"
            id="add-service-btn"
            class="btn btn-primary"
            style="margin-top:10px; font-size:13px;"
          >
            + Add another service
          </button>

          <button type="submit" class="btn btn-primary dashboard-submit" style="margin-top:12px;">
            Save services
          </button>
        </form>
      </section>
    </main>
  </div>

  <!-- Template for new empty service card -->
  <script type="text/template" id="service-template">
    <div class="service-card" style="border:1px solid #e5e7eb; border-radius:12px; padding:12px; box-shadow:0 4px 10px rgba(15,23,42,0.04);">
      <div style="display:grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap:10px;">
        <label>
          <span style="font-size:12px; color:#4b5563;">Service name</span>
          <input
            type="text"
            name="service_name[]"
            placeholder="Brake service, AC recharge, etc."
            required
          >
        </label>

        <label>
          <span style="font-size:12px; color:#4b5563;">Starting price ($)</span>
          <input
            type="number"
            step="0.01"
            min="0"
            name="service_price[]"
            placeholder="120.00"
            required
          >
        </label>
      </div>

      <details style="margin-top:8px;">
        <summary style="font-size:12px; color:#6b7280; cursor:pointer;">
          Refinements (optional)
        </summary>

        <div style="margin-top:8px; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
          <label>
            <span style="font-size:12px; color:#4b5563;">Makes (comma separated)</span>
            <input
              type="text"
              name="service_makes[]"
              placeholder="Toyota, Hyundai, Honda"
            >
          </label>

          <label>
            <span style="font-size:12px; color:#4b5563;">Models (comma separated)</span>
            <input
              type="text"
              name="service_models[]"
              placeholder="Camry, Sonata"
            >
          </label>

          <label>
            <span style="font-size:12px; color:#4b5563;">Years (range or specific)</span>
            <input
              type="text"
              name="service_years[]"
              placeholder="2015–2021 or 2018"
            >
          </label>
        </div>

        <label style="margin-top:8px; display:block;">
          <span style="font-size:12px; color:#4b5563;">Notes</span>
          <textarea
            name="service_notes[]"
            rows="2"
            placeholder="Any important details (front only, includes parts, etc.)"
            style="width:100%; font-size:13px;"
          ></textarea>
        </label>
      </details>

      <button
        type="button"
        class="btn btn-ghost remove-service-btn"
        style="margin-top:8px; font-size:12px; padding:4px 10px;"
      >
        Remove service
      </button>
    </div>
  </script>

  <script>
    // Add new service card
    document.getElementById('add-service-btn').addEventListener('click', function () {
      const container = document.getElementById('services-container');
      const template  = document.getElementById('service-template').innerHTML;
      container.insertAdjacentHTML('beforeend', template);
    });

    // Remove service card
    document.addEventListener('click', function (e) {
      if (e.target.classList.contains('remove-service-btn')) {
        const card = e.target.closest('.service-card');
        if (card) {
          card.remove();
        }
      }
    });
  </script>
</body>
</html>

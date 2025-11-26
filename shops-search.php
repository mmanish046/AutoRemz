<?php
// shops-search.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();

// --- DB SETTINGS ---
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO: your DB user
$password   = "HostingerDBpinetree90601@";   // TODO: your DB password
$dbname     = "u138912455_autoremz_db";        // TODO: your DB name

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(["error" => "DB connection failed"]);
  exit;
}

$city    = isset($_GET['city']) ? trim($_GET['city']) : '';
$service = isset($_GET['service']) ? trim($_GET['service']) : '';

$sql = "SELECT id, shop_name, email, city, phone, services FROM shops WHERE 1";
$params = [];
$types  = "";

if ($city !== "") {
  $sql .= " AND city LIKE ?";
  $params[] = "%{$city}%";
  $types    .= "s";
}

$sql .= " ORDER BY created_at DESC LIMIT 200";
$stmt = $conn->prepare($sql);

if (!empty($params)) {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  // Decode services JSON safely
  $services = [];
  if (!empty($row['services'])) {
    $decoded = json_decode($row['services'], true);
    if (is_array($decoded)) $services = $decoded; // [{name, price}]
  }

  // Optional: estimate price for requested service (case-insensitive)
  $estPrice = null;
  if ($service !== "" && !empty($services)) {
    foreach ($services as $s) {
      if (isset($s['name']) && strcasecmp($s['name'], $service) === 0) {
        $estPrice = isset($s['price']) ? floatval($s['price']) : null;
        break;
      }
    }
  }

  $out[] = [
    "id"         => (int)$row["id"],
    "name"       => $row["shop_name"],
    "city"       => $row["city"] ?: "",
    "services"   => $services,          // full list back to UI
    "estPrice"   => $estPrice,          // null if not found
    "rating"     => null,               // (future: add ratings table)
    "reviewCount"=> null,
    "distanceMiles" => null,            // (future: add geocoding)
    "isOpenNow"  => true,               // (placeholder)
    "responseMinutes" => 15             // (placeholder)
  ];
}

$stmt->close();
$conn->close();

echo json_encode(["shops" => $out], JSON_UNESCAPED_UNICODE);

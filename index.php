<?php
session_start();

$isUser   = isset($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? null;

// DB settings (same as the rest of your project)
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$resCount        = 0;
$quoteCount      = 0;
$hasResUnread    = false;
$hasQuoteUnread  = false;

if ($isUser) {
    $userId = (int) $_SESSION['user_id'];

    $conn = new mysqli($servername, $username, $password, $dbname);
    if (!$conn->connect_error) {
        // Total reservations (not archived)
        $sql = "
          SELECT COUNT(*) AS c
          FROM reservations
          WHERE user_id = ?
            AND (is_archived IS NULL OR is_archived = 0)
        ";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($c);
            if ($stmt->fetch()) {
                $resCount = (int)$c;
            }
            $stmt->close();
        }

        // This is the only change, and this is another one
        //Test git merge
        
        // Total custom requests
        $sql = "
          SELECT COUNT(*) AS c
          FROM quote_requests
          WHERE user_id = ?
        ";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($c);
            if ($stmt->fetch()) {
                $quoteCount = (int)$c;
            }
            $stmt->close();
        }

        // Any reservation with last message from shop? => unread for user
        $sql = "
          SELECT m.sender_type
          FROM reservation_messages m
          JOIN reservations r ON m.reservation_id = r.id
          WHERE r.user_id = ?
            AND (r.is_archived IS NULL OR r.is_archived = 0)
            AND m.created_at = (
              SELECT MAX(created_at)
              FROM reservation_messages
              WHERE reservation_id = r.id
            )
          ORDER BY m.created_at DESC
          LIMIT 1
        ";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($senderType);
            if ($stmt->fetch() && $senderType === 'shop') {
                $hasResUnread = true;
            }
            $stmt->close();
        }

        // Any custom quote thread (not booked) with last message from shop? => unread
        $sql = "
          SELECT qm.sender_type
          FROM quote_messages qm
          JOIN quote_requests_shops qrs ON qm.quote_rel_id = qrs.id
          JOIN quote_requests qr        ON qrs.quote_id  = qr.id
          WHERE qr.user_id = ?
            AND qr.status <> 'booked'
            AND qm.created_at = (
              SELECT MAX(created_at)
              FROM quote_messages
              WHERE quote_rel_id = qm.quote_rel_id
            )
          ORDER BY qm.created_at DESC
          LIMIT 1
        ";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($senderType2);
            if ($stmt->fetch() && $senderType2 === 'shop') {
                $hasQuoteUnread = true;
            }
            $stmt->close();
        }

        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>AutoRemz – Auto repair made easy</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="styles.css" />

  <style>
    body {
      background:#F3F4F6;
    }

    main {
      display:grid;
      grid-template-columns:minmax(0,1.45fr) minmax(0,1fr);
      gap:18px;
    }
    @media (max-width:900px){
      main{grid-template-columns:minmax(0,1fr);}
    }

    .hero{
      background:#FFFFFF;
      border-radius:24px;
      border:1px solid #E5E7EB;
      box-shadow:0 18px 40px rgba(15,23,42,0.06);
      padding:24px 24px 20px;
      display:flex;
      flex-direction:column;
      gap:18px;
    }

    .eyebrow{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:4px 10px;
      border-radius:999px;
      background:#ECFDF3;
      border:1px solid #BBF7D0;
      color:#166534;
      font-size:12px;
    }
    .pill-dot{
      width:7px;height:7px;border-radius:999px;
      background:#22C55E;
      box-shadow:0 0 0 4px rgba(34,197,94,0.35);
    }

    .hero h1{
      margin:0;
      font-size:clamp(26px,3.5vw,32px);
      letter-spacing:0.01em;
      color:#111827;
    }
    .hero h1 .accent{
      color:#DC2626;
    }
    .hero-subtitle{
      margin:8px 0 0;
      color:#4B5563;
      max-width:32rem;
      font-size:14px;
    }

    .hero-badges{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-top:4px;
    }
    .hero-badges span{
      font-size:11px;
      padding:4px 9px;
      border-radius:999px;
      background:#F9FAFB;
      border:1px solid #E5E7EB;
      color:#4B5563;
    }
    .hero-badges span strong{color:#111827;}

    .search-panel{
      margin-top:10px;
      padding:14px 14px 10px;
      border-radius:18px;
      background:#F9FAFB;
      border:1px solid #E5E7EB;
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    .field-row{
      display:grid;
      grid-template-columns:minmax(0,1fr) minmax(0,1fr);
      gap:10px;
    }
    @media (max-width:700px){
      .field-row{grid-template-columns:minmax(0,1fr);}
    }
    .field label{
      font-size:11px;
      color:#374151;
      margin-bottom:4px;
      display:block;
    }
    .field-shell{
      display:flex;
      align-items:center;
      gap:6px;
      background:#FFFFFF;
      border-radius:999px;
      border:1px solid #D1D5DB;
      padding:4px 10px;
    }
    .field-shell .icon{font-size:13px;}
    .field-shell input,
    .field-shell select{
      border:none;
      outline:none;
      flex:1;
      font-size:13px;
      background:#FFFFFF;
      color:#111827;
    }
    .field-shell input::placeholder{color:#9CA3AF;}

    .search-actions{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:8px;
      flex-wrap:wrap;
      font-size:12px;
      color:#6B7280;
    }

    .activity-strip{
      margin-top:10px;
      font-size:12px;
      color:#9CA3AF;
    }
    .activity-strip a{
      color:#111827;
      text-decoration:none;
      margin:0 4px;
      font-size:12px;
    }
    .activity-dot{
      display:inline-flex;
      width:6px;height:6px;
      border-radius:999px;
      background:#FB923C;
      box-shadow:0 0 0 4px rgba(251,146,60,0.25);
      margin-left:2px;
    }

    .how-it-works{
      margin-top:16px;
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:12px;
      font-size:12px;
      color:#4B5563;
    }
    @media (max-width:900px){
      .how-it-works{grid-template-columns:minmax(0,1fr);}
    }
    .how-card{
      background:#FFFFFF;
      border-radius:16px;
      border:1px solid #E5E7EB;
      padding:10px 12px;
      display:flex;
      flex-direction:column;
      gap:4px;
    }
    .how-label{
      font-size:11px;
      text-transform:uppercase;
      letter-spacing:0.08em;
      color:#9CA3AF;
    }
    .how-title{
      font-weight:600;
      color:#111827;
    }

    .stats-card{
      background:#FFFFFF;
      border-radius:24px;
      border:1px solid #E5E7EB;
      box-shadow:0 18px 40px rgba(15,23,42,0.05);
      padding:18px 18px 20px;
      display:flex;
      flex-direction:column;
      gap:14px;
    }
    .stats-header{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:8px;
    }
    .stats-title{
      font-size:14px;
      color:#111827;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .status-dot{
      width:9px;height:9px;
      border-radius:999px;
      background:#22C55E;
      box-shadow:0 0 0 5px rgba(34,197,94,0.2);
    }
    .stats-tag{
      background:#F3F4F6;
      color:#4B5563;
      border-radius:999px;
      padding:4px 10px;
      font-size:11px;
      border:1px solid #E5E7EB;
      white-space:nowrap;
    }

    .results-header{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:8px;
      margin-top:4px;
      font-size:12px;
      color:#374151;
    }
    .sort-select{
      background:#FFFFFF;
      border-radius:999px;
      padding:4px 9px;
      border:1px solid #D1D5DB;
      font-size:11px;
      color:#374151;
    }

    .shop-list{
      margin-top:6px;
      border-radius:16px;
      border:1px solid #E5E7EB;
      background:#F9FAFB;
      padding:10px;
      min-height:120px;
      display:flex;
      flex-direction:column;
      gap:8px;
    }
    
    .activity-strip {
    margin-top: 12px;
    padding: 8px 14px;
    background: #F3F4F6;
    border-radius: 12px;
    border: 1px solid #E5E7EB;
    display: inline-flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    color: #6B7280;
}

.activity-strip a {
    color: #111827;
    font-weight: 500;
}

.activity-dot {
    display:inline-flex;
    width:7px; height:7px;
    border-radius:999px;
    background:#FB923C;
    box-shadow:0 0 0 3px rgba(251,146,60,0.25);
}


    footer{margin-top:16px;font-size:12px;color:#9CA3AF;}
    footer a{color:#6B7280;text-decoration:none;margin-left:4px;}
  </style>
</head>
<body>
  <div class="page">
    <header>
      <div class="brand">
        <a href="index.php">
          <img src="brand_logo" alt="Autoremz logo" class="logo" />
        </a>
      </div>

      <div class="header-actions">
        <?php if ($isUser): ?>
          <span style="font-size:12px; color:#6b7280; margin-right:8px;">
            Logged in as <?php echo htmlspecialchars($userName ?? ($_SESSION['user_email'] ?? '')); ?>
          </span>
          <a href="user-dashboard.php" class="btn btn-ghost">My dashboard</a>
          <a href="user-logout.php" class="btn btn-primary">Log out</a>
        <?php else: ?>
          <a href="shop-signin.php" class="btn btn-ghost">For shops</a>
          <a href="signin.html" class="btn btn-primary">Sign in</a>
        <?php endif; ?>
      </div>
    </header>

    <main>
      <!-- Left: hero + search + how it works -->
      <section class="hero">
        <div class="eyebrow">
          <div class="pill-dot"></div>
          Smarter car repair · One request, multiple shops
        </div>
        
        

        
        
        <div>
          <h1>
            Find the <span class="accent">right shop</span> for the repair you actually need.
          </h1>
          <p class="hero-subtitle">
            Dont ever Tell AutoRemz what’s wrong with your car and we’ll match you with nearby repair shops
            that specialize in that kind of work. Compare options, chat in one place, and book when
            you’re ready.
          </p>
        </div>
        <section class="how-it-works">
          <div class="how-card">
            <span class="how-label">Step 1</span>
            <span class="how-title">Tell us about the issue</span>
            <span>Choose a service or describe the problem in a custom request.</span>
          </div>
          <div class="how-card">
            <span class="how-label">Step 2</span>
            <span class="how-title">Shops respond to you</span>
            <span>Local repair shops review your request and mark interest.</span>
          </div>
          <div class="how-card">
            <span class="how-label">Step 3</span>
            <span class="how-title">Chat & book online</span>
            <span>Confirm details, pick a time, and keep everything in one thread.</span>
          </div>
        </section>
        <div class="hero-badges">
          <span><strong>One request</strong> sent to multiple shops</span>
          <span><strong>Direct chat</strong> with interested shops</span>
          <span><strong>No calls</strong> or email threads</span>
        </div>

        <!-- Search panel -->
        <section class="search-panel">
          <div class="field-row">
            <div>
              <label for="service-select">What do you need help with?</label>
              <div class="field-shell">
                <span class="icon">🔧</span>
                <select id="service-select">
                  <option value="">Select a service</option>
                  <option value="Oil Change">Oil change</option>
                  <option value="Brakes">Brakes</option>
                  <option value="Engine Diagnostics">Engine diagnostics</option>
                  <option value="Tires">Tires & alignment</option>
                  <option value="AC Service">AC / heating</option>
                  <option value="Transmission">Transmission</option>
                  <option value="Battery">Battery & electrical</option>
                  <option value="Other">Something else</option>
                </select>
              </div>
            </div>

            <div>
              <label for="location-input">Where are you located?</label>
              <div class="field-shell">
                <span class="icon">📍</span>
                <input
                  id="location-input"
                  type="text"
                  placeholder="City or ZIP (optional)"
                />
              </div>
            </div>
          </div>

          <div class="search-actions">
            <span>
              <span id="matching-count">0 shops</span> match your current filters
            </span>
            <button id="search-btn" class="btn btn-primary">
              Find matching
            </button>
          </div>

          <div style="margin-top:6px; font-size:11px; color:#6b7280;">
            Can’t see the service you need?
            <button
              type="button"
              class="btn btn-ghost"
              onclick="window.location.href='custom-quote.php';"
              style="padding:4px 8px; font-size:11px; margin-left:4px;"
            >
              Send a custom repair request
            </button>
          </div>

          <div id="error-message" class="error-message" style="display:none; font-size:12px; color:#ef4444; margin-top:6px;">
            <div id="status-message" style="display:none; color:#ef4444; margin-bottom:4px;"></div>
            Please select a service to get started.
          </div>
        </section>

        <?php if ($isUser): ?>
          <div class="activity-strip">
            Your activity:
            <a href="my-reservations.php">
              My reservations (<?php echo $resCount; ?>)
              <?php if ($hasResUnread): ?><span class="activity-dot"></span><?php endif; ?>
            </a>
            ·
            <a href="my-quotes.php">
              My custom requests (<?php echo $quoteCount; ?>)
              <?php if ($hasQuoteUnread): ?><span class="activity-dot"></span><?php endif; ?>
            </a>
          </div>
        <?php endif; ?>





        <!-- How it works -->

      </section>

      <!-- Right: matching shops list -->
      <aside class="stats-card">
        <div class="stats-header">
          <div class="stats-title">
            <span class="status-dot"></span>
            Shops that match your request
          </div>
          <div class="stats-tag">
            Results update as you search
          </div>
        </div>

        <div class="results-header">
          <div><strong>Matching shops</strong></div>
          <select id="sort-select" class="sort-select">
            <option value="best">Sort: Best match</option>
            <option value="rating">Sort: Rating</option>
            <option value="price">Sort: Estimated price</option>
            <option value="distance">Sort: Distance</option>
          </select>
        </div>

        <div id="shop-list" class="shop-list">
          <!-- shop cards rendered by JS -->
        </div>
      </aside>
    </main>

    <footer>
      © <span id="year"></span> AutoRemz
      <span>
        · <a href="#">Privacy</a>
        · <a href="#">Terms</a>
        · <a href="shop-signin.php">For repair shops</a>
      </span>
    </footer>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", () => {
      const serviceSelect = document.getElementById("service-select");
      const locationInput = document.getElementById("location-input");
      const searchBtn     = document.getElementById("search-btn");
      const matchingCount = document.getElementById("matching-count");
      const shopList      = document.getElementById("shop-list");
      const sortSelect    = document.getElementById("sort-select");
      const statusMessage = document.getElementById("status-message");
      const errorMessage  = document.getElementById("error-message");

      if (!serviceSelect || !locationInput || !searchBtn || !matchingCount || !shopList) {
        console.error("Missing required elements on homepage.");
        return;
      }

      const STORAGE_KEY = "autoremzFilters_v2";

      function saveFilters() {
        const filters = {
          service: serviceSelect.value || "",
          city: (locationInput.value || "").trim(),
          sort: sortSelect ? sortSelect.value : ""
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(filters));
      }

      function loadFilters() {
        try {
          const raw = localStorage.getItem(STORAGE_KEY);
          if (!raw) return;
          const parsed = JSON.parse(raw);
          if (parsed.service) serviceSelect.value = parsed.service;
          if (parsed.city)    locationInput.value = parsed.city;
          if (parsed.sort && sortSelect) sortSelect.value = parsed.sort;
        } catch (err) {
          console.warn("Failed to load saved filters", err);
        }
      }

      function setStatus(msg) {
        if (!statusMessage) return;
        if (!msg) {
          statusMessage.style.display = "none";
          statusMessage.textContent = "";
        } else {
          statusMessage.style.display = "block";
          statusMessage.textContent = msg;
        }
      }

      function renderShops(shops) {
        shopList.innerHTML = "";

        if (!shops || !shops.length) {
          const div = document.createElement("div");
          div.className = "shop-card";
          div.innerHTML = `
            <div class="shop-main">
              <div class="shop-name">No matching shops found.</div>
              <div class="shop-meta">Try another city or service.</div>
            </div>
          `;
          shopList.appendChild(div);
          matchingCount.textContent = "0 shops";
          return;
        }

        const selectedService = serviceSelect.value;
        matchingCount.textContent = shops.length + (shops.length === 1 ? " shop" : " shops");

        shops.forEach((shop) => {
          const card = document.createElement("article");
          card.className = "shop-card";

          const ratingText = (shop.rating != null)
            ? `${shop.rating.toFixed ? shop.rating.toFixed(1) : shop.rating}/5`
            : `New`;

          const distanceText = (typeof shop.distanceMiles === "number")
            ? `${shop.distanceMiles.toFixed(1)} mi away`
            : ``;

          const openStatus = shop.isOpenNow ? "Open now" : "Closed";
          const openClass  = shop.isOpenNow ? "" : "dot-divider";

          // Service tags
          let serviceTags = "";
          if (Array.isArray(shop.services)) {
            serviceTags = shop.services.map(s => {
              const name = (s && s.name) ? s.name : "";
              const mark = (
                selectedService &&
                name &&
                name.toLowerCase() === selectedService.toLowerCase()
              ) ? "✓ " : "";
              return `<span class="service-tag">${mark}${name}</span>`;
            }).join("");
          }

          const priceText = (shop.estPrice != null)
            ? `<span>$${Number(shop.estPrice).toFixed(2)}</span>`
            : `<span>—</span>`;

          card.innerHTML = `
  <div class="shop-main">
    <div class="shop-name-row">
      <div class="shop-name">${shop.name || "Unnamed shop"}</div>
      <div class="rating-pill">
        <span class="star">★</span>
        ${ratingText}
      </div>
    </div>
    <div class="shop-meta">
      <span>${shop.city || ""}</span>
      ${distanceText ? `<span class="dot-divider">${distanceText}</span>` : ""}
      <span class="${openClass}">${openStatus}</span>
    </div>
    <div class="service-tags">${serviceTags}</div>
  </div>
  <div class="shop-cta">
    <div class="price-pill">
      Est. for ${selectedService || "service"}: ${priceText}
    </div>
    <div class="shop-cta-buttons">
      <a href="shop.php?id=${encodeURIComponent(shop.id)}" class="btn btn-ghost" style="padding-inline:10px; font-size:12px;">
        View shop
      </a>
      <button type="button" data-id="${shop.id}">
        Request quote
      </button>
    </div>
    <small>Avg response: ${(shop.responseMinutes || 15)} min</small>
  </div>
          `;

          const button = card.querySelector("button");
          if (button) {
            button.addEventListener("click", () => {
              // Later: send them into a custom quote flow prefilled with this shop/service.
              window.location.href = `custom-quote.php?shop_id=${encodeURIComponent(shop.id)}&service=${encodeURIComponent(selectedService || "")}`;
            });
          }

          shopList.appendChild(card);
        });
      }

      async function fetchShops() {
        const service = serviceSelect.value;
        const city    = locationInput.value.trim();
        const sort    = sortSelect ? sortSelect.value : "";

        if (!service) {
          if (errorMessage) errorMessage.style.display = "block";
          setStatus("");
          renderShops([]);
          return;
        }

        if (errorMessage) errorMessage.style.display = "none";
        setStatus("Looking for shops that match your request…");

        try {
          const params = new URLSearchParams();
          params.set("service", service);
          if (city) params.set("city", city);
          if (sort) params.set("sort", sort);

          const url = "./shops-search.php" + (params.toString() ? `?${params.toString()}` : "");
          const res = await fetch(url, {
            headers: { "Accept": "application/json" },
            cache: "no-store"
          });

          if (!res.ok) {
            const text = await res.text().catch(() => "");
            throw new Error(`Server error ${res.status}. ${text}`);
          }

          const data = await res.json();
          if (!data || !Array.isArray(data.shops)) {
            throw new Error("Invalid response format from server (no 'shops' array).");
          }

          setStatus("");
          renderShops(data.shops);
        } catch (err) {
          console.error(err);
          setStatus("Something went wrong while loading shops. Please try again.");
          renderShops([]);
        }
      }

      async function updateUI() {
        saveFilters();
        await fetchShops();
      }

      searchBtn.addEventListener("click", (e) => {
        e.preventDefault();
        updateUI();
      });

      if (sortSelect) {
        sortSelect.addEventListener("change", () => {
          if (serviceSelect.value) {
            updateUI();
          }
        });
      }

      loadFilters();
      if (serviceSelect.value) {
        updateUI();
      } else {
        renderShops([]);
      }

      const yearSpan = document.getElementById("year");
      if (yearSpan) yearSpan.textContent = new Date().getFullYear();
    });
  </script>
</body>
</html>

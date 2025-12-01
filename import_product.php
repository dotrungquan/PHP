<?php
// Yêu cầu PHP 7.4+
// Cần extension mysqli bật sẵn

function prompt($msg) {
    echo $msg;
    return trim(fgets(STDIN));
}

// Kết nối DB
function connectDB($user, $pass, $db) {
    $mysqli = new mysqli('localhost', $user, $pass, $db);
    if ($mysqli->connect_error) {
        die("Kết nối thất bại: " . $mysqli->connect_error . "\n");
    }
    $mysqli->set_charset("utf8mb4");
    return $mysqli;
}

// Chạy query, báo lỗi nếu có
function runQuery($mysqli, $sql) {
    if (!$mysqli->query($sql)) {
        echo "Lỗi query: " . $mysqli->error . "\nSQL: $sql\n";
        exit(1);
    }
}

echo "=== Script gộp sản phẩm WooCommerce bằng SQL & WP-CLI ===\n";

// Nhập thông tin DB
$user = prompt("Nhập username MySQL: ");
$pass = prompt("Nhập password MySQL: ");
$db = prompt("Nhập database name (web đích - Web B): ");

$prefixA = prompt("Nhập prefix DB A (web gốc): ");
$prefixB = prompt("Nhập prefix DB B (web đích): ");

echo "Chọn kiểu gộp:\n";
echo "1. Web B TRỐNG (copy thẳng)\n";
echo "2. Web B ĐÃ CÓ sản phẩm (remap ID)\n";
$mode = prompt("Nhập lựa chọn (1 hoặc 2): ");

$mysqli = connectDB($user, $pass, $db);

// Hàm chạy copy dữ liệu thẳng (mode 1)
function copyStraight($mysqli, $prefixA, $prefixB) {
    echo "Bắt đầu copy dữ liệu thẳng...\n";
    $queries = [
        // Copy sản phẩm và biến thể
        "INSERT INTO {$prefixB}posts SELECT * FROM {$prefixA}posts WHERE post_type IN ('product','product_variation')",
        // Copy postmeta
        "INSERT INTO {$prefixB}postmeta SELECT pm.* FROM {$prefixA}postmeta pm JOIN {$prefixA}posts p ON pm.post_id=p.ID WHERE p.post_type IN ('product','product_variation')",
        // Copy ảnh đính kèm
        "INSERT INTO {$prefixB}posts SELECT * FROM {$prefixA}posts WHERE post_type='attachment'",
        "INSERT INTO {$prefixB}postmeta SELECT pm.* FROM {$prefixA}postmeta pm JOIN {$prefixA}posts p ON pm.post_id=p.ID WHERE p.post_type='attachment'",
        // Copy terms, taxonomy, relationships
        "INSERT IGNORE INTO {$prefixB}terms SELECT * FROM {$prefixA}terms",
        "INSERT IGNORE INTO {$prefixB}term_taxonomy SELECT * FROM {$prefixA}term_taxonomy",
        "INSERT IGNORE INTO {$prefixB}term_relationships SELECT tr.* FROM {$prefixA}term_relationships tr JOIN {$prefixA}posts p ON tr.object_id=p.ID WHERE p.post_type IN ('product','product_variation')"
    ];
    foreach ($queries as $sql) {
        echo "Running query:\n$sql\n";
        runQuery($mysqli, $sql);
    }
    echo "Copy dữ liệu thẳng hoàn tất.\n";
}

// Hàm remap ID (mode 2)
function remapAndMerge($mysqli, $prefixA, $prefixB) {
    echo "Bắt đầu remap và gộp dữ liệu...\n";

    // 1. Lấy max ID hiện tại
    $res = $mysqli->query("SELECT MAX(ID) as maxid FROM {$prefixB}posts");
    $row = $res->fetch_assoc();
    $maxID = $row['maxid'] ?? 1000000;
    $offset = $maxID + 1000;

    echo "Max post ID hiện tại trong DB đích: $maxID. Offset sử dụng: $offset\n";

    // 2. Tạo bảng tạm mapping ID
    runQuery($mysqli, "DROP TEMPORARY TABLE IF EXISTS tmp_post_id_map");
    runQuery($mysqli, "CREATE TEMPORARY TABLE tmp_post_id_map (old_id BIGINT PRIMARY KEY, new_id BIGINT NOT NULL)");

    runQuery($mysqli, "INSERT INTO tmp_post_id_map (old_id, new_id) SELECT ID, ID + $offset FROM {$prefixA}posts");

    // 3. Chèn posts remap ID
    $sql = "
    INSERT INTO {$prefixB}posts
    (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status,
     ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered,
     post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
    SELECT
      m.new_id,
      p.post_author, p.post_date, p.post_date_gmt, p.post_content, p.post_title, p.post_excerpt, p.post_status, p.comment_status,
      p.ping_status, p.post_password, p.post_name, p.to_ping, p.pinged, p.post_modified, p.post_modified_gmt, p.post_content_filtered,
      COALESCE(m2.new_id, 0),
      p.guid, p.menu_order, p.post_type, p.post_mime_type, p.comment_count
    FROM {$prefixA}posts p
    JOIN tmp_post_id_map m ON p.ID = m.old_id
    LEFT JOIN tmp_post_id_map m2 ON p.post_parent = m2.old_id
    ";
    runQuery($mysqli, $sql);

    // 4. Chèn postmeta remap ID
    $sql = "
    INSERT INTO {$prefixB}postmeta (post_id, meta_key, meta_value)
    SELECT m.new_id, pm.meta_key, pm.meta_value
    FROM {$prefixA}postmeta pm
    JOIN tmp_post_id_map m ON pm.post_id = m.old_id
    ";
    runQuery($mysqli, $sql);

    // 5. Chèn term_relationships remap ID
    $sql = "
    INSERT INTO {$prefixB}term_relationships (object_id, term_taxonomy_id)
    SELECT m.new_id, tr.term_taxonomy_id
    FROM {$prefixA}term_relationships tr
    JOIN tmp_post_id_map m ON tr.object_id = m.old_id
    ";
    runQuery($mysqli, $sql);

    // 6. Chèn terms và term_taxonomy (INSERT IGNORE tránh lỗi trùng)
    runQuery($mysqli, "INSERT IGNORE INTO {$prefixB}terms SELECT * FROM {$prefixA}terms");
    runQuery($mysqli, "INSERT IGNORE INTO {$prefixB}term_taxonomy SELECT * FROM {$prefixA}term_taxonomy");

    echo "Remap và gộp dữ liệu hoàn tất.\n";
}

echo "Bạn muốn chạy lệnh WP-CLI sau khi gộp không? (y/n): ";
$runWpCli = strtolower(trim(fgets(STDIN)));

if ($mode == '1') {
    copyStraight($mysqli, $prefixA, $prefixB);
} elseif ($mode == '2') {
    remapAndMerge($mysqli, $prefixA, $prefixB);
} else {
    echo "Lựa chọn không hợp lệ. Dừng script.\n";
    exit(1);
}

if ($runWpCli === 'y') {
    echo "Nhập đường dẫn WP-CLI (ví dụ: /usr/bin/wp hoặc wp): ";
    $wpcliPath = trim(fgets(STDIN));
    echo "Nhập user ID dùng chạy WP-CLI (ví dụ: 1): ";
    $wpUserId = trim(fgets(STDIN));

    echo "Nhập URL web gốc (web A) (ví dụ: https://webA.com): ";
    $urlA = trim(fgets(STDIN));
    echo "Nhập URL web đích (web B) (ví dụ: https://webB.com): ";
    $urlB = trim(fgets(STDIN));

    $commands = [
        "$wpcliPath wc tool run regenerate_product_lookup_tables --user=$wpUserId",
        "$wpcliPath wc tool run clear_transients --user=$wpUserId",
        "$wpcliPath search-replace '$urlA' '$urlB' --skip-columns=guid",
    ];

    foreach ($commands as $cmd) {
        echo "Chạy: $cmd\n";
        system($cmd);
    }
}

echo "Hoàn tất script.\n";

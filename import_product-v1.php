<?php
/**
 * import_product.php (v2)
 * Gộp sản phẩm WooCommerce từ Web A (gốc) sang Web B (đích) bằng SQL.
 *
 * Yêu cầu: PHP 7.4+, extension mysqli, chạy bằng CLI.
 * Chỉ import: product, product_variation, ảnh liên quan, term/thuộc tính sản phẩm.
 * KHÔNG copy file ảnh: cần tự rsync thư mục wp-content/uploads từ A sang B.
 */

if (PHP_SAPI !== 'cli') {
    die("Chỉ chạy bằng CLI.\n");
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------------------------------------------------------------- Helpers
function prompt(string $msg, string $default = ''): string
{
    echo $msg . ($default !== '' ? " [$default]" : '') . ': ';
    $v = trim((string) fgets(STDIN));
    return $v === '' ? $default : $v;
}

function promptHidden(string $msg): string
{
    echo $msg . ': ';
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        system('stty -echo');
    }
    $v = trim((string) fgets(STDIN));
    if ($tty) {
        system('stty echo');
        echo "\n";
    }
    return $v;
}

function confirm(string $msg): bool
{
    return strtolower(prompt($msg . ' (y/n)', 'n')) === 'y';
}

function ident(string $v, string $label): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $v)) {
        fwrite(STDERR, "$label không hợp lệ (chỉ cho phép chữ, số, dấu _): $v\n");
        exit(1);
    }
    return $v;
}

// ---------------------------------------------------------------- Importer
final class Importer
{
    private const PRODUCT_TAX = ['product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class'];
    private const ID_META_KEYS = ['_thumbnail_id', '_product_image_gallery', '_upsell_ids', '_crosssell_ids', '_children'];
    private const SKIP_META_KEYS = ['_edit_lock', '_edit_last'];
    private const STATUS_EXCLUDE = "('auto-draft','trash')";

    private mysqli $m;
    private string $dbA;
    private string $pA;
    private string $dbB;
    private string $pB;
    private bool $keepIds;
    private int $authorId;
    private string $urlA;
    private string $urlB;
    private string $mapTbl;
    private string $ttTbl;

    /** @var array<int,int> old post id => new post id */
    private array $postMap = [];
    /** @var array<int,int> old term_taxonomy_id => new */
    private array $ttMap = [];
    /** @var array<int,int> old term_id => new term_id */
    private array $termMap = [];
    /** @var array<int,int> term mới tạo ở B: old term_id => new term_id */
    private array $createdTerms = [];

    public function __construct(mysqli $m, string $dbA, string $pA, string $dbB, string $pB, bool $keepIds, int $authorId, string $urlA, string $urlB)
    {
        $this->m = $m;
        $this->dbA = $dbA;
        $this->pA = $pA;
        $this->dbB = $dbB;
        $this->pB = $pB;
        $this->keepIds = $keepIds;
        $this->authorId = $authorId;
        $this->urlA = $urlA;
        $this->urlB = $urlB;
        $this->mapTbl = "`{$dbB}`.`tmp_import_post_map`";
        $this->ttTbl = "`{$dbB}`.`tmp_import_tt_map`";
        // Tránh lỗi ngày '0000-00-00' của bản nháp cũ
        $this->m->query("SET SESSION sql_mode = ''");
    }

    private function a(string $n): string
    {
        return "`{$this->dbA}`.`{$this->pA}{$n}`";
    }

    private function b(string $n): string
    {
        return "`{$this->dbB}`.`{$this->pB}{$n}`";
    }

    private function scalar(string $sql)
    {
        $r = $this->m->query($sql);
        $row = $r->fetch_row();
        $r->free();
        return $row[0] ?? null;
    }

    private function q(string $v): string
    {
        return "'" . $this->m->real_escape_string($v) . "'";
    }

    /** Bước chuẩn bị (có DDL nên nằm ngoài transaction). Trả về thống kê. */
    public function prepare(): array
    {
        $m = $this->m;
        $this->cleanup();
        $m->query("CREATE TABLE {$this->mapTbl} (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, old_id BIGINT UNSIGNED NOT NULL UNIQUE, new_id BIGINT UNSIGNED NULL) ENGINE=InnoDB");
        $m->query("CREATE TABLE {$this->ttTbl} (old_tt BIGINT UNSIGNED PRIMARY KEY, new_tt BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB");

        $ex = self::STATUS_EXCLUDE;
        $posts = $this->a('posts');

        // 1) Sản phẩm + biến thể (chỉ biến thể có cha được import)
        $m->query("INSERT IGNORE INTO {$this->mapTbl} (old_id)
            SELECT ID FROM $posts
            WHERE post_status NOT IN $ex
              AND (post_type = 'product'
                   OR (post_type = 'product_variation'
                       AND post_parent IN (SELECT ID FROM $posts WHERE post_type = 'product' AND post_status NOT IN $ex)))
            ORDER BY ID");

        // 2) Ảnh: thumbnail, gallery, ảnh danh mục, và attachment có cha là sản phẩm
        $ids = [];
        $res = $m->query("SELECT pm.meta_value FROM {$this->a('postmeta')} pm
            JOIN {$this->mapTbl} x ON pm.post_id = x.old_id
            WHERE pm.meta_key IN ('_thumbnail_id','_product_image_gallery')");
        while ($r = $res->fetch_row()) {
            foreach (explode(',', (string) $r[0]) as $v) {
                if (ctype_digit(trim($v))) {
                    $ids[(int) $v] = true;
                }
            }
        }
        $res->free();
        $res = $m->query("SELECT tm.meta_value FROM {$this->a('termmeta')} tm
            JOIN {$this->a('term_taxonomy')} tt ON tt.term_id = tm.term_id
            WHERE tm.meta_key = 'thumbnail_id' AND tt.taxonomy = 'product_cat'");
        while ($r = $res->fetch_row()) {
            if (ctype_digit((string) $r[0])) {
                $ids[(int) $r[0]] = true;
            }
        }
        $res->free();
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            $list = implode(',', array_map('intval', $chunk));
            $m->query("INSERT IGNORE INTO {$this->mapTbl} (old_id) SELECT ID FROM $posts WHERE post_type = 'attachment' AND ID IN ($list)");
        }
        $m->query("INSERT IGNORE INTO {$this->mapTbl} (old_id)
            SELECT p.ID FROM $posts p JOIN {$this->mapTbl} x ON p.post_parent = x.old_id
            WHERE p.post_type = 'attachment'");

        // 3) Tính ID mới
        if ($this->keepIds) {
            $m->query("UPDATE {$this->mapTbl} SET new_id = old_id");
            $clash = (int) $this->scalar("SELECT COUNT(*) FROM {$this->b('posts')} b JOIN {$this->mapTbl} x ON b.ID = x.new_id");
            if ($clash > 0) {
                $this->cleanup();
                throw new RuntimeException("Có $clash ID bị trùng trong Web B. Hãy chạy lại và chọn chế độ ID mới (2).");
            }
        } else {
            $maxB = (int) $this->scalar("SELECT COALESCE(MAX(ID),0) FROM {$this->b('posts')}");
            $ai = (int) $this->scalar("SELECT COALESCE(AUTO_INCREMENT,0) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = {$this->q($this->dbB)} AND TABLE_NAME = {$this->q($this->pB . 'posts')}");
            $base = max($maxB, $ai - 1);
            $m->query("UPDATE {$this->mapTbl} SET new_id = $base + seq");
        }

        $res = $m->query("SELECT old_id, new_id FROM {$this->mapTbl}");
        while ($r = $res->fetch_row()) {
            $this->postMap[(int) $r[0]] = (int) $r[1];
        }
        $res->free();

        // Thống kê + cảnh báo
        $stats = [];
        $res = $m->query("SELECT p.post_type, COUNT(*) FROM {$this->mapTbl} x JOIN $posts p ON p.ID = x.old_id GROUP BY p.post_type");
        while ($r = $res->fetch_row()) {
            $stats[$r[0]] = (int) $r[1];
        }
        $res->free();
        $stats['_sku_trung'] = (int) $this->scalar("SELECT COUNT(*) FROM {$this->a('postmeta')} a
            JOIN {$this->mapTbl} x ON a.post_id = x.old_id AND a.meta_key = '_sku' AND a.meta_value <> ''
            JOIN {$this->b('postmeta')} b ON b.meta_key = '_sku' AND b.meta_value = a.meta_value");
        $stats['_engine'] = (string) $this->scalar("SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = {$this->q($this->dbB)} AND TABLE_NAME = {$this->q($this->pB . 'posts')}");
        return $stats;
    }

    /** Thực thi import trong 1 transaction. */
    public function execute(): void
    {
        $m = $this->m;
        $m->begin_transaction();
        try {
            $this->step('Thuộc tính sản phẩm', fn() => $this->importAttributeTaxonomies());
            $this->step('Terms', fn() => $this->importTerms());
            $this->step('Posts', fn() => $this->importPosts());
            $this->step('Postmeta', fn() => $this->importPostmeta());
            $this->step('Meta chứa ID (ảnh, upsell, ...)', fn() => $this->importIdMeta());
            $this->step('Term relationships', fn() => $this->importRelationships());
            $this->step('Termmeta', fn() => $this->importTermmeta());
            $m->commit();
        } catch (Throwable $e) {
            $m->rollback();
            $this->cleanup();
            throw $e;
        }
        $this->cleanup();
    }

    public function cleanup(): void
    {
        $this->m->query("DROP TABLE IF EXISTS {$this->mapTbl}");
        $this->m->query("DROP TABLE IF EXISTS {$this->ttTbl}");
    }

    private function step(string $name, callable $fn): void
    {
        echo " - $name ... ";
        $fn();
        echo "xong\n";
    }

    private function importAttributeTaxonomies(): void
    {
        $this->m->query("INSERT INTO {$this->b('woocommerce_attribute_taxonomies')}
            (attribute_name, attribute_label, attribute_type, attribute_orderby, attribute_public)
            SELECT a.attribute_name, a.attribute_label, a.attribute_type, a.attribute_orderby, a.attribute_public
            FROM {$this->a('woocommerce_attribute_taxonomies')} a
            WHERE NOT EXISTS (SELECT 1 FROM {$this->b('woocommerce_attribute_taxonomies')} b WHERE b.attribute_name = a.attribute_name)");
    }

    /** Ghép term theo (taxonomy, slug); chưa có thì tạo mới với ID mới. */
    private function importTerms(): void
    {
        $in = "'" . implode("','", self::PRODUCT_TAX) . "'";
        $res = $this->m->query("SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, tt.parent, t.name, t.slug, t.term_group
            FROM {$this->a('term_taxonomy')} tt
            JOIN {$this->a('terms')} t ON t.term_id = tt.term_id
            WHERE tt.taxonomy IN ($in) OR tt.taxonomy LIKE 'pa\\_%'");
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->free();

        $find = $this->m->prepare("SELECT tt.term_taxonomy_id, tt.term_id FROM {$this->b('term_taxonomy')} tt
            JOIN {$this->b('terms')} t ON t.term_id = tt.term_id WHERE tt.taxonomy = ? AND t.slug = ? LIMIT 1");
        $insT = $this->m->prepare("INSERT INTO {$this->b('terms')} (name, slug, term_group) VALUES (?, ?, ?)");
        $insTT = $this->m->prepare("INSERT INTO {$this->b('term_taxonomy')} (term_id, taxonomy, description, parent, count) VALUES (?, ?, ?, 0, 0)");

        $created = [];
        foreach ($rows as $r) {
            $oldTT = (int) $r['term_taxonomy_id'];
            $oldTerm = (int) $r['term_id'];
            $find->bind_param('ss', $r['taxonomy'], $r['slug']);
            $find->execute();
            $find->bind_result($fTT, $fTerm);
            if ($find->fetch()) {
                $this->ttMap[$oldTT] = (int) $fTT;
                $this->termMap[$oldTerm] = (int) $fTerm;
                $find->free_result();
                continue;
            }
            $find->free_result();

            $tg = (int) $r['term_group'];
            $insT->bind_param('ssi', $r['name'], $r['slug'], $tg);
            $insT->execute();
            $newTerm = (int) $this->m->insert_id;
            $insTT->bind_param('iss', $newTerm, $r['taxonomy'], $r['description']);
            $insTT->execute();
            $newTT = (int) $this->m->insert_id;

            $this->ttMap[$oldTT] = $newTT;
            $this->termMap[$oldTerm] = $newTerm;
            $this->createdTerms[$oldTerm] = $newTerm;
            $created[] = [$r, $newTT];
        }

        // Gán parent cho term mới tạo
        $upd = $this->m->prepare("UPDATE {$this->b('term_taxonomy')} SET parent = ? WHERE term_taxonomy_id = ?");
        foreach ($created as [$r, $newTT]) {
            $oldParent = (int) $r['parent'];
            if ($oldParent > 0) {
                $np = $this->termMap[$oldParent] ?? 0;
                $upd->bind_param('ii', $np, $newTT);
                $upd->execute();
            }
        }

        // Ghi map term_taxonomy_id ra bảng để dùng trong SQL
        foreach (array_chunk($this->ttMap, 500, true) as $chunk) {
            $vals = [];
            foreach ($chunk as $o => $n) {
                $vals[] = '(' . (int) $o . ',' . (int) $n . ')';
            }
            $this->m->query("INSERT INTO {$this->ttTbl} (old_tt, new_tt) VALUES " . implode(',', $vals));
        }
    }

    private function importPosts(): void
    {
        $guid = ($this->urlA !== '' && $this->urlB !== '')
            ? 'REPLACE(p.guid, ' . $this->q($this->urlA) . ', ' . $this->q($this->urlB) . ')'
            : 'p.guid';
        $author = (int) $this->authorId;

        $this->m->query("INSERT INTO {$this->b('posts')}
            (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status,
             ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered,
             post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
            SELECT x.new_id, $author, p.post_date, p.post_date_gmt, p.post_content, p.post_title, p.post_excerpt, p.post_status, p.comment_status,
             p.ping_status, p.post_password,
             CASE WHEN p.post_type = 'product' AND EXISTS (
                    SELECT 1 FROM {$this->b('posts')} e WHERE e.post_type = 'product' AND e.post_name = p.post_name AND e.post_status <> 'trash')
                  THEN CONCAT(LEFT(p.post_name, 190), '-imported') ELSE p.post_name END,
             p.to_ping, p.pinged, p.post_modified, p.post_modified_gmt, p.post_content_filtered,
             COALESCE(px.new_id, 0), $guid, p.menu_order, p.post_type, p.post_mime_type, p.comment_count
            FROM {$this->a('posts')} p
            JOIN {$this->mapTbl} x ON p.ID = x.old_id
            LEFT JOIN {$this->mapTbl} px ON p.post_parent = px.old_id");
    }

    private function importPostmeta(): void
    {
        $skip = "'" . implode("','", array_merge(self::ID_META_KEYS, self::SKIP_META_KEYS)) . "'";
        $this->m->query("INSERT INTO {$this->b('postmeta')} (post_id, meta_key, meta_value)
            SELECT x.new_id, pm.meta_key, pm.meta_value
            FROM {$this->a('postmeta')} pm JOIN {$this->mapTbl} x ON pm.post_id = x.old_id
            WHERE pm.meta_key NOT IN ($skip)");
    }

    private function importIdMeta(): void
    {
        $keys = "'" . implode("','", self::ID_META_KEYS) . "'";
        $res = $this->m->query("SELECT pm.post_id, pm.meta_key, pm.meta_value
            FROM {$this->a('postmeta')} pm JOIN {$this->mapTbl} x ON pm.post_id = x.old_id
            WHERE pm.meta_key IN ($keys)");
        $ins = $this->m->prepare("INSERT INTO {$this->b('postmeta')} (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
        while ($r = $res->fetch_assoc()) {
            $val = $this->remapMetaValue($r['meta_key'], (string) $r['meta_value']);
            if ($val === null) {
                continue; // đối tượng tham chiếu không được import -> bỏ
            }
            $newPost = $this->postMap[(int) $r['post_id']];
            $ins->bind_param('iss', $newPost, $r['meta_key'], $val);
            $ins->execute();
        }
        $res->free();
    }

    private function remapMetaValue(string $key, string $v): ?string
    {
        if ($key === '_thumbnail_id') {
            $id = (int) $v;
            return isset($this->postMap[$id]) ? (string) $this->postMap[$id] : null;
        }
        if ($key === '_product_image_gallery') {
            $out = [];
            foreach (explode(',', $v) as $id) {
                $id = (int) trim($id);
                if (isset($this->postMap[$id])) {
                    $out[] = $this->postMap[$id];
                }
            }
            return $out ? implode(',', $out) : null;
        }
        // _upsell_ids, _crosssell_ids, _children: mảng serialize
        $arr = @unserialize($v, ['allowed_classes' => false]);
        if (!is_array($arr)) {
            return null;
        }
        $out = [];
        foreach ($arr as $id) {
            if (isset($this->postMap[(int) $id])) {
                $out[] = $this->postMap[(int) $id];
            }
        }
        return $out ? serialize($out) : null;
    }

    private function importRelationships(): void
    {
        $this->m->query("INSERT IGNORE INTO {$this->b('term_relationships')} (object_id, term_taxonomy_id, term_order)
            SELECT x.new_id, tm.new_tt, tr.term_order
            FROM {$this->a('term_relationships')} tr
            JOIN {$this->mapTbl} x ON tr.object_id = x.old_id
            JOIN {$this->ttTbl} tm ON tm.old_tt = tr.term_taxonomy_id");
        // Cập nhật count cho các term liên quan
        $this->m->query("UPDATE {$this->b('term_taxonomy')} tt
            JOIN {$this->ttTbl} tm ON tm.new_tt = tt.term_taxonomy_id
            SET tt.count = (SELECT COUNT(*) FROM {$this->b('term_relationships')} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id)");
    }

    /** Copy termmeta cho term mới tạo (remap thumbnail_id của danh mục). */
    private function importTermmeta(): void
    {
        $sel = $this->m->prepare("SELECT meta_key, meta_value FROM {$this->a('termmeta')} WHERE term_id = ?");
        $ins = $this->m->prepare("INSERT INTO {$this->b('termmeta')} (term_id, meta_key, meta_value) VALUES (?, ?, ?)");
        foreach ($this->createdTerms as $oldTerm => $newTerm) {
            $sel->bind_param('i', $oldTerm);
            $sel->execute();
            $sel->bind_result($k, $v);
            $rows = [];
            while ($sel->fetch()) {
                $rows[] = [$k, $v];
            }
            $sel->free_result();
            foreach ($rows as [$k, $v]) {
                if ($k === 'thumbnail_id') {
                    $v = isset($this->postMap[(int) $v]) ? (string) $this->postMap[(int) $v] : null;
                    if ($v === null) {
                        continue;
                    }
                }
                $ins->bind_param('iss', $newTerm, $k, $v);
                $ins->execute();
            }
        }
    }
}

// ---------------------------------------------------------------- Main
echo "=== Gộp sản phẩm WooCommerce (Web A -> Web B) ===\n";

$host = prompt('MySQL host', 'localhost');
$user = prompt('MySQL username');
$pass = promptHidden('MySQL password');

$dbA = ident(prompt('Database Web A (gốc)'), 'Database A');
$pA  = ident(prompt('Prefix Web A', 'wp_'), 'Prefix A');
$dbB = ident(prompt('Database Web B (đích)', $dbA), 'Database B');
$pB  = ident(prompt('Prefix Web B', 'wp_'), 'Prefix B');

if ($dbA === $dbB && $pA === $pB) {
    fwrite(STDERR, "Web A và Web B trùng nhau (cùng DB, cùng prefix).\n");
    exit(1);
}

echo "Chọn kiểu gộp:\n";
echo "  1. Giữ nguyên ID (chỉ khi không trùng ID trong Web B)\n";
echo "  2. Cấp ID mới (an toàn khi Web B đã có dữ liệu)\n";
$mode = prompt('Lựa chọn', '2');
if (!in_array($mode, ['1', '2'], true)) {
    fwrite(STDERR, "Lựa chọn không hợp lệ.\n");
    exit(1);
}

$authorId = (int) prompt('User ID (Web B) làm tác giả cho sản phẩm', '1');
$urlA = rtrim(prompt('URL Web A (vd https://a.com, bỏ trống nếu không thay)'), '/');
$urlB = rtrim(prompt('URL Web B (vd https://b.com)'), '/');

// Backup
if (confirm("Tạo backup DB '$dbB' bằng mysqldump ngay bây giờ?")) {
    $file = "backup_{$dbB}_" . date('Ymd_His') . '.sql';
    putenv('MYSQL_PWD=' . $pass);
    system('mysqldump -h ' . escapeshellarg($host) . ' -u ' . escapeshellarg($user)
        . ' --single-transaction ' . escapeshellarg($dbB) . ' > ' . escapeshellarg($file), $rc);
    putenv('MYSQL_PWD');
    if ($rc !== 0) {
        fwrite(STDERR, "Backup thất bại, dừng script.\n");
        exit(1);
    }
    echo "Đã backup: $file\n";
} elseif (!confirm('Xác nhận bạn ĐÃ tự backup DB Web B?')) {
    echo "Hãy backup trước rồi chạy lại.\n";
    exit(1);
}

try {
    $mysqli = new mysqli($host, $user, $pass, $dbB);
    $mysqli->set_charset('utf8mb4');

    $imp = new Importer($mysqli, $dbA, $pA, $dbB, $pB, $mode === '1', $authorId, $urlA, $urlB);
    $stats = $imp->prepare();

    echo "\n--- Sẽ import ---\n";
    foreach ($stats as $k => $v) {
        if ($k[0] !== '_') {
            echo "  $k: $v\n";
        }
    }
    if ($stats['_engine'] !== 'InnoDB') {
        echo "CẢNH BÁO: bảng posts của B là '{$stats['_engine']}', transaction/rollback KHÔNG có tác dụng.\n";
    }
    if ($stats['_sku_trung'] > 0) {
        echo "CẢNH BÁO: {$stats['_sku_trung']} SKU đã tồn tại trong Web B (sẽ bị trùng SKU).\n";
    }
    if (!confirm('Tiếp tục import?')) {
        $imp->cleanup();
        echo "Đã huỷ.\n";
        exit(0);
    }

    $imp->execute();
    echo "Import hoàn tất.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "LỖI: " . $e->getMessage() . "\nĐã rollback, DB Web B không bị thay đổi.\n");
    exit(1);
}

// ---------------------------------------------------------------- WP-CLI
if (confirm("\nChạy WP-CLI hậu xử lý (lookup table, recount, search-replace, flush cache)?")) {
    $wp = prompt('Lệnh WP-CLI', 'wp');
    if (!preg_match('/^[A-Za-z0-9_\/.\- ]+$/', $wp)) {
        fwrite(STDERR, "Đường dẫn WP-CLI không hợp lệ.\n");
        exit(1);
    }
    $wpPath = prompt('Đường dẫn thư mục WordPress của Web B (--path)');
    $uid = (int) prompt('User ID chạy lệnh wc', '1');
    $root = confirm('Thêm --allow-root?');
    $base = $wp . ' --path=' . escapeshellarg($wpPath) . ($root ? ' --allow-root' : '');

    $cmds = [
        "$base wc tool run regenerate_product_lookup_tables --user=$uid",
        "$base wc tool run recount_terms --user=$uid",
        "$base wc tool run clear_transients --user=$uid",
    ];
    if ($urlA !== '' && $urlB !== '') {
        $tables = escapeshellarg("{$pB}posts") . ' ' . escapeshellarg("{$pB}postmeta") . ' ' . escapeshellarg("{$pB}termmeta");
        $sr = "$base search-replace " . escapeshellarg($urlA) . ' ' . escapeshellarg($urlB)
            . " $tables --skip-columns=guid";
        echo "Chạy thử (dry-run):\n";
        system("$sr --dry-run");
        if (confirm('Áp dụng search-replace thật?')) {
            $cmds[] = $sr;
        }
    }
    $cmds[] = "$base cache flush";
    $cmds[] = "$base rewrite flush";

    foreach ($cmds as $cmd) {
        echo "Chạy: $cmd\n";
        system($cmd);
    }
}

echo "\nHoàn tất. Đừng quên copy thư mục wp-content/uploads từ Web A sang Web B (rsync).\n";

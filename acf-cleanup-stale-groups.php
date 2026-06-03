<?php
/**
 * Одноразовая чистка лишних ACF-групп (DB-посты без актуального JSON).
 *
 * Запуск на ПРОДЕ (консоль Railway / там где есть wp-cli):
 *   DRY-RUN (только показать, ничего не удаляя):
 *     wp eval-file acf-cleanup-stale-groups.php
 *   РЕАЛЬНОЕ УДАЛЕНИЕ:
 *     wp eval-file acf-cleanup-stale-groups.php apply
 *
 * Логика: оставляем только группы из ALLOWLIST (13 консолидированных JSON-групп).
 * Любой DB-пост типа acf-field-group с другим ключом считается устаревшим
 * (старые поблочные группы Hero/Services/Reviews/CTA/... и дубликаты) и удаляется.
 * PHP-регистрируемые группы (acf_add_local_field_group) и JSON-only группы
 * не имеют DB-поста, поэтому скрипт их физически не трогает.
 */

$apply = in_array('apply', $args ?? [], true);

$ALLOW = [
    'group_about_page',
    'group_blog_page',
    'group_blog_post',
    'group_contact_page',
    'group_home_page',
    'group_location_fields',
    'group_portfolio_fields',
    'group_portfolio_page',
    'group_process_page',
    'group_service_fields',
    'group_services_page',
    'group_success_stories_page',
    'group_success_story',
];

$posts = get_posts([
    'post_type'      => 'acf-field-group',
    'posts_per_page' => -1,
    'post_status'    => ['publish', 'acf-disabled', 'draft', 'trash', 'pending', 'private'],
]);

echo "Mode: " . ($apply ? "APPLY (will delete)" : "DRY-RUN (no changes)") . "\n";
echo "Total acf-field-group DB posts: " . count($posts) . "\n\n";

$to_delete = [];
foreach ($posts as $p) {
    // ключ группы = post_name, но у трэшнутых может быть суффикс __trashed
    $key  = preg_replace('/__trashed$/', '', $p->post_name);
    $keep = in_array($key, $ALLOW, true);
    printf("%-7s DB#%-5d %-32s status=%-12s %s\n",
        $keep ? 'KEEP' : 'DELETE', $p->ID, $key, $p->post_status, $p->post_title);
    if (!$keep) {
        $to_delete[] = $p->ID;
    }
}

echo "\nTo delete: " . count($to_delete) . " | To keep: " . (count($posts) - count($to_delete)) . "\n";

if (!$apply) {
    echo "\nDRY-RUN — ничего не удалено. Перепроверь список выше и запусти с 'apply' для удаления.\n";
    return;
}

foreach ($to_delete as $id) {
    // ACF-посты нельзя в корзину штатно — удаляем через ACF API (чистит и связанные поля)
    if (function_exists('acf_delete_field_group')) {
        acf_delete_field_group($id);
    } else {
        wp_delete_post($id, true);
    }
    echo "deleted DB#$id\n";
}
echo "\nDone. Deleted " . count($to_delete) . " stale group(s).\n";
echo "Примечание: orphaned postmeta (значения удалённых полей) остаётся в БД — безвредно, фронт читает по имени только существующие поля.\n";

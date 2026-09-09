<?php
declare(strict_types=1);

if (!defined('TEKG_ROOT_DIR')) {
    define('TEKG_ROOT_DIR', __DIR__);
}
if (!defined('TEKG_APP_URL_BASE')) {
    define('TEKG_APP_URL_BASE', '/tedee');
}
if (!defined('TEKG_PRODUCT_NAME')) {
    define('TEKG_PRODUCT_NAME', 'TE-Dee');
}
if (!defined('TEKG_PRODUCT_FULL_NAME')) {
    define('TEKG_PRODUCT_FULL_NAME', 'Transposable Element Data Exploration Engine');
}
if (!defined('TEKG_ASSETS_FS_DIR')) {
    define('TEKG_ASSETS_FS_DIR', TEKG_ROOT_DIR . '/assets');
}
if (!defined('TEKG_ASSETS_URL_BASE')) {
    define('TEKG_ASSETS_URL_BASE', TEKG_APP_URL_BASE . '/assets');
}
if (!defined('TEKG_API_FS_DIR')) {
    define('TEKG_API_FS_DIR', TEKG_ROOT_DIR . '/api');
}
if (!defined('TEKG_API_URL_BASE')) {
    define('TEKG_API_URL_BASE', TEKG_APP_URL_BASE . '/api');
}
if (!defined('TEKG_DATA_FS_DIR')) {
    define('TEKG_DATA_FS_DIR', TEKG_ROOT_DIR . '/data');
}
if (!defined('TEKG_DATA_URL_BASE')) {
    define('TEKG_DATA_URL_BASE', TEKG_APP_URL_BASE . '/data');
}
if (!defined('TEKG_TEMPLATES_FS_DIR')) {
    define('TEKG_TEMPLATES_FS_DIR', TEKG_ROOT_DIR . '/templates');
}
if (!defined('TEKG_SCRIPTS_FS_DIR')) {
    define('TEKG_SCRIPTS_FS_DIR', TEKG_ROOT_DIR . '/scripts');
}
if (!defined('TEKG_IMPORTS_FS_DIR')) {
    define('TEKG_IMPORTS_FS_DIR', TEKG_ROOT_DIR . '/imports');
}
if (!defined('TEKG_TAXONOMY_ROOT_FS_DIR')) {
    define('TEKG_TAXONOMY_ROOT_FS_DIR', TEKG_DATA_FS_DIR . '/taxonomy');
}
if (!defined('TEKG_TAXONOMY_FS_DIR')) {
    define('TEKG_TAXONOMY_FS_DIR', TEKG_TAXONOMY_ROOT_FS_DIR . '/transposon_tree');
}
if (!defined('TEKG_TAXONOMY_TE234_FS_DIR')) {
    define('TEKG_TAXONOMY_TE234_FS_DIR', TEKG_TAXONOMY_ROOT_FS_DIR . '/te_234');
}
if (!defined('TEKG_TAXONOMY_LINEAGE_FS_DIR')) {
    define('TEKG_TAXONOMY_LINEAGE_FS_DIR', TEKG_TAXONOMY_ROOT_FS_DIR . '/lineage');
}
if (!defined('TEKG_TERMINOLOGY_FS_DIR')) {
    define('TEKG_TERMINOLOGY_FS_DIR', TEKG_DATA_FS_DIR . '/terminology');
}
if (!defined('TEKG_CACHE_FS_DIR')) {
    define('TEKG_CACHE_FS_DIR', TEKG_DATA_FS_DIR . '/cache');
}
if (!defined('TEKG_LOGS_FS_DIR')) {
    define('TEKG_LOGS_FS_DIR', TEKG_DATA_FS_DIR . '/logs');
}
if (!defined('TEKG_JBROWSE_FS_DIR')) {
    define('TEKG_JBROWSE_FS_DIR', TEKG_DATA_FS_DIR . '/JBrowse');
}
if (!defined('TEKG_JBROWSE_URL_BASE')) {
    define('TEKG_JBROWSE_URL_BASE', TEKG_DATA_URL_BASE . '/JBrowse');
}
if (!defined('TEKG_EXPRESSION_BULK_FS_DIR')) {
    define('TEKG_EXPRESSION_BULK_FS_DIR', TEKG_DATA_FS_DIR . '/bulk_expression_web');
}
if (!defined('TEKG_EXPRESSION_BULK_URL_BASE')) {
    define('TEKG_EXPRESSION_BULK_URL_BASE', TEKG_DATA_URL_BASE . '/bulk_expression_web');
}

function tekg_normalize_relative_path(string $relativePath): string
{
    return ltrim(str_replace('\\', '/', $relativePath), '/');
}

function tekg_join_path(string $basePath, string $suffix = ''): string
{
    $normalized = tekg_normalize_relative_path($suffix);
    return $normalized === '' ? $basePath : ($basePath . '/' . $normalized);
}

function tekg_fs_from_project_relative(string $relativePath): string
{
    return tekg_join_path(TEKG_ROOT_DIR, $relativePath);
}

function tekg_url_from_project_relative(string $relativePath): string
{
    return tekg_join_path(TEKG_APP_URL_BASE, $relativePath);
}

function tekg_app_url(string $suffix = ''): string
{
    return tekg_join_path(TEKG_APP_URL_BASE, $suffix);
}

function tekg_assets_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_ASSETS_FS_DIR, $suffix);
}

function tekg_assets_url(string $suffix = ''): string
{
    return tekg_join_path(TEKG_ASSETS_URL_BASE, $suffix);
}

function tekg_api_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_API_FS_DIR, $suffix);
}

function tekg_api_url(string $suffix = ''): string
{
    return tekg_join_path(TEKG_API_URL_BASE, $suffix);
}

function tekg_data_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_DATA_FS_DIR, $suffix);
}

function tekg_data_url(string $suffix = ''): string
{
    return tekg_join_path(TEKG_DATA_URL_BASE, $suffix);
}

function tekg_templates_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_TEMPLATES_FS_DIR, $suffix);
}

function tekg_scripts_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_SCRIPTS_FS_DIR, $suffix);
}

function tekg_imports_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_IMPORTS_FS_DIR, $suffix);
}

function tekg_taxonomy_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_TAXONOMY_FS_DIR, $suffix);
}

function tekg_taxonomy_te234_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_TAXONOMY_TE234_FS_DIR, $suffix);
}

function tekg_taxonomy_lineage_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_TAXONOMY_LINEAGE_FS_DIR, $suffix);
}

function tekg_terminology_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_TERMINOLOGY_FS_DIR, $suffix);
}

function tekg_cache_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_CACHE_FS_DIR, $suffix);
}

function tekg_logs_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_LOGS_FS_DIR, $suffix);
}

function tekg_jbrowse_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_JBROWSE_FS_DIR, $suffix);
}

function tekg_jbrowse_url(string $suffix = ''): string
{
    return tekg_join_path(TEKG_JBROWSE_URL_BASE, $suffix);
}

function tekg_expression_bulk_fs_path(string $suffix = ''): string
{
    return tekg_join_path(TEKG_EXPRESSION_BULK_FS_DIR, $suffix);
}

function tekg_expression_bulk_url(string $suffix = ''): string
{
    return tekg_join_path(TEKG_EXPRESSION_BULK_URL_BASE, $suffix);
}

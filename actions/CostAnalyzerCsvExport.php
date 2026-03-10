<?php declare(strict_types = 0);

namespace Modules\ZabbixFinOpsToolkit\Actions;

use API,
CController,
CControllerResponseData,
CRoleHelper;

class CostAnalyzerCsvExport extends CController {

private const ANALYSIS_DAYS       = 30;
private const CPU_AVG_THRESHOLD   = 20;
private const RAM_AVG_THRESHOLD   = 40;
private const CPU_MAX_THRESHOLD   = 60;
private const RAM_MAX_THRESHOLD   = 80;
private const DISK_HIGH_THRESHOLD = 85;
private const RIGHT_SIZE_FACTOR = 0.80;

private const ITEM_KEY_CPU       = 'system.cpu.util';
private const ITEM_KEY_RAM_UTIL  = 'vm.memory.utilization';
private const ITEM_KEY_RAM_UTIL_WIN = 'vm.memory.util';
private const ITEM_KEY_RAM_PAVAIL = 'vm.memory.size[pavailable]';
private const ITEM_KEY_DISK_PREFIX = 'vfs.fs.size[';
private const ITEM_KEY_DISK_SUFFIX = ',pused]';
private const ITEM_KEY_NETIN     = 'net.if.in';
private const ITEM_KEY_NETOUT    = 'net.if.out';
private const ITEM_KEY_LOAD      = 'system.cpu.load';
private const ITEM_KEY_LOAD_WIN_SEARCH = 'Processor Queue Length';
private const ITEM_KEY_CPU_NUM   = 'system.cpu.num';
private const ITEM_KEY_CPU_NUM_WIN_SEARCH = 'NumberOfLogicalProcessors';
private const ITEM_KEY_RAM_TOTAL = 'vm.memory.size[total]';

protected function init(): void {
$this->disableCsrfValidation();
}

protected function checkInput(): bool {
$fields = [
'filter_groupids' => 'array'
];

return $this->validateInput($fields);
}

protected function checkPermissions(): bool {
return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
}

protected function doAction(): void {
$filter_groupids = $this->getInput('filter_groupids', []);

$now = time();
$time_from = $now - (self::ANALYSIS_DAYS * 86400);
$first_week_start = $time_from;
$first_week_end   = $time_from + (7 * 86400);
$last_week_start  = $now - (7 * 86400);
$last_week_end    = $now;

$host_options = [
'output'            => ['hostid', 'host', 'name'],
'selectHostGroups'  => ['groupid', 'name'],
'selectTags'        => ['tag', 'value'],
'filter'            => ['status' => HOST_STATUS_MONITORED],
'monitored_hosts'   => true,
'preservekeys'      => true,
'limit'             => 1000
];

if (!empty($filter_groupids)) {
$host_options['groupids'] = $filter_groupids;
}

$hosts = API::Host()->get($host_options);
$hostids = array_keys($hosts);

$items = API::Item()->get([
'output'      => ['itemid', 'hostid', 'key_', 'value_type', 'units', 'lastvalue'],
'hostids'     => $hostids,
'search'      => [
'key_' => [
self::ITEM_KEY_CPU, self::ITEM_KEY_RAM_UTIL, self::ITEM_KEY_RAM_UTIL_WIN, self::ITEM_KEY_RAM_PAVAIL,
self::ITEM_KEY_DISK_PREFIX, self::ITEM_KEY_NETIN, self::ITEM_KEY_NETOUT,
self::ITEM_KEY_LOAD, self::ITEM_KEY_LOAD_WIN_SEARCH, self::ITEM_KEY_CPU_NUM, self::ITEM_KEY_CPU_NUM_WIN_SEARCH, self::ITEM_KEY_RAM_TOTAL
]
],
'searchByAny' => true,
'monitored'   => true,
'preservekeys'=> true
]);

$host_items = [];
foreach ($items as $item) {
$hid = $item['hostid'];
$key = $item['key_'];

if (!isset($host_items[$hid]['disks'])) {
$host_items[$hid]['disks'] = [];
}

if ($key === self::ITEM_KEY_CPU) {
$host_items[$hid]['cpu'] = $item;
}
elseif ($key === self::ITEM_KEY_RAM_UTIL || $key === self::ITEM_KEY_RAM_UTIL_WIN) {
$host_items[$hid]['ram'] = $item;
$host_items[$hid]['ram_inverted'] = false;
}
elseif ($key === self::ITEM_KEY_RAM_PAVAIL && !isset($host_items[$hid]['ram'])) {
$host_items[$hid]['ram'] = $item;
$host_items[$hid]['ram_inverted'] = true;
}
elseif (strpos($key, self::ITEM_KEY_DISK_PREFIX) === 0 && substr_compare($key, self::ITEM_KEY_DISK_SUFFIX, -strlen(self::ITEM_KEY_DISK_SUFFIX)) === 0) {
$host_items[$hid]['disks'][] = $item;
}
elseif (strpos($key, self::ITEM_KEY_NETIN) === 0) {
$host_items[$hid]['net_in'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_NETOUT) === 0) {
$host_items[$hid]['net_out'] = $item;
}
elseif (strpos($key, self::ITEM_KEY_LOAD) === 0 || strpos($key, self::ITEM_KEY_LOAD_WIN_SEARCH) !== false) {
$host_items[$hid]['load'] = $item;
}
elseif ($key === self::ITEM_KEY_CPU_NUM || strpos($key, self::ITEM_KEY_CPU_NUM_WIN_SEARCH) !== false) {
$host_items[$hid]['cpu_num'] = $item;
}
elseif ($key === self::ITEM_KEY_RAM_TOTAL) {
$host_items[$hid]['ram_total'] = $item;
}
}

$results = [];
foreach ($hosts as $hostid => $host) {
if (!isset($host_items[$hostid])) {
continue;
}

$hi = $host_items[$hostid];
$group_names = array_column($host['hostgroups'], 'name');

$r = [
'host_name'  => $host['name'],
'host_groups'=> implode('; ', $group_names),
'cpu_avg'    => null, 'cpu_max' => null, 'cpu_p95' => null,
'ram_avg'    => null, 'ram_max' => null, 'ram_p95' => null,
'disk_avg'   => null,
'net_in_avg' => null, 'net_out_avg' => null,
'load_avg'   => null,
'waste_score'      => null,
'efficiency_score' => null,
'cpu_trend'  => null, 'ram_trend' => null,
'cpu_count'  => null, 'ram_total_gb' => null,
'cpu_recommended' => null, 'ram_recommended_gb' => null,
'is_azure'   => false, 'azure_sku' => null, 'current_cost' => null, 'recommended_cost' => null, 'monthly_savings' => null,
'recommendation' => ''
];

if (isset($host['tags']) && is_array($host['tags'])) {
foreach ($host['tags'] as $tag) {
if (stripos($tag['tag'], 'azure_sku') !== false) {
$r['azure_sku'] = trim($tag['value'] ?? '');
$r['is_azure'] = true;
} elseif (stripos($tag['tag'], 'azure') !== false || stripos($tag['value'] ?? '', 'azure') !== false) {
$r['is_azure'] = true;
}
}
}

if (isset($hi['cpu'])) {
$d = $this->getTrendData($hi['cpu'], $time_from, $now);
$r['cpu_avg'] = $d['avg'];
$r['cpu_max'] = $d['max'];
$r['cpu_p95'] = $this->getP95Peak($hi['cpu'], $time_from, $now);
$r['cpu_trend'] = $this->calculateTrend($hi['cpu'], $first_week_start, $first_week_end, $last_week_start, $last_week_end);
}

if (isset($hi['ram'])) {
$d = $this->getTrendData($hi['ram'], $time_from, $now);
$ram_inverted = !empty($hi['ram_inverted']);

if ($ram_inverted && $d['avg'] !== null) {
$r['ram_avg'] = round(100 - $d['avg'], 2);
$r['ram_max'] = ($d['min'] !== null) ? round(100 - $d['min'], 2) : null;
$r['ram_p95'] = $r['ram_max'];
}
else {
$r['ram_avg'] = $d['avg'];
$r['ram_max'] = $d['max'];
$r['ram_p95'] = $this->getP95Peak($hi['ram'], $time_from, $now);
}

foreach (['ram_avg', 'ram_max', 'ram_p95'] as $ram_key) {
if ($r[$ram_key] !== null) {
$r[$ram_key] = round(max(0, min(100, (float) $r[$ram_key])), 2);
}
}

$ram_trend_raw = $this->calculateTrend($hi['ram'], $first_week_start, $first_week_end, $last_week_start, $last_week_end);
$r['ram_trend'] = ($ram_inverted && $ram_trend_raw !== null) ? round(-$ram_trend_raw, 2) : $ram_trend_raw;
}

if (!empty($hi['disks'])) {
$highest_disk_avg = null;
foreach ($hi['disks'] as $disk_item) {
$disk_data = $this->getTrendData($disk_item, $time_from, $now);
if ($disk_data['avg'] !== null) {
if ($highest_disk_avg === null || $disk_data['avg'] > $highest_disk_avg) {
$highest_disk_avg = $disk_data['avg'];
}
}
}
$r['disk_avg'] = $highest_disk_avg;
}
if (isset($hi['net_in'])) {
$r['net_in_avg'] = $this->getTrendData($hi['net_in'], $time_from, $now)['avg'];
}
if (isset($hi['net_out'])) {
$r['net_out_avg'] = $this->getTrendData($hi['net_out'], $time_from, $now)['avg'];
}
if (isset($hi['load'])) {
$r['load_avg'] = $this->getTrendData($hi['load'], $time_from, $now)['avg'];
}

// Hardware specs.
if (isset($hi['cpu_num']) && !empty($hi['cpu_num']['lastvalue'])) {
$r['cpu_count'] = (int) $hi['cpu_num']['lastvalue'];
}
if (isset($hi['ram_total']) && !empty($hi['ram_total']['lastvalue'])) {
$r['ram_total_gb'] = round((float) $hi['ram_total']['lastvalue'] / 1073741824, 1);
}

if ($r['cpu_avg'] !== null && $r['ram_avg'] !== null) {
$avg_usage = ($r['cpu_avg'] + $r['ram_avg']) / 2;
$r['waste_score'] = max(0, min(100, round(100 - $avg_usage, 1)));
$r['efficiency_score'] = max(0, min(100, round($avg_usage, 1)));
}

$r['recommendation'] = $this->generateRecommendation($r);
$r = $this->calculateRightSizing($r);

if ($r['is_azure'] && $r['cpu_count'] !== null && $r['ram_total_gb'] !== null) {
$r['current_cost'] = $this->estimateAzureCost($r['cpu_count'], $r['ram_total_gb'], $r['azure_sku']);

if ($r['cpu_recommended'] !== null || $r['ram_recommended_gb'] !== null) {
$rec_cpu = $r['cpu_recommended'] ?? $r['cpu_count'];
$rec_ram = $r['ram_recommended_gb'] ?? $r['ram_total_gb'];

$rec_sku = null;
if ($r['azure_sku'] !== null) {
$rec_sku = preg_replace('/([a-zA-Z]+)'.$r['cpu_count'].'([a-zA-Z_]+)/i', '${1}'.$rec_cpu.'${2}', $r['azure_sku']);
if ($rec_sku === $r['azure_sku']) {
$rec_sku = null;
}
}

$r['recommended_cost'] = $this->estimateAzureCost((int)$rec_cpu, (float)$rec_ram, $rec_sku);

if ($r['current_cost'] !== null && $r['recommended_cost'] !== null && $r['current_cost'] > $r['recommended_cost']) {
$r['monthly_savings'] = $r['current_cost'] - $r['recommended_cost'];
}
}
}

$results[] = $r;
}

usort($results, function ($a, $b) {
return ($b['waste_score'] ?? 0) <=> ($a['waste_score'] ?? 0);
});

$response = new CControllerResponseData([
'results' => $results
]);
$response->setFileName('finops_cost_analysis_'.date('Y-m-d').'.csv');
$this->setResponse($response);
}

private function getTrendData(array $item, int $time_from, int $time_till): array {
$table = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64) ? 'trends_uint' : 'trends';

$sql = 'SELECT AVG(value_avg) AS avg_val, MAX(value_max) AS max_val, MIN(value_min) AS min_val'.
' FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($time_from).
' AND clock<='.zbx_dbstr($time_till);

$row = \DBfetch(\DBselect($sql));

return [
'avg' => ($row && $row['avg_val'] !== null) ? round((float) $row['avg_val'], 2) : null,
'max' => ($row && $row['max_val'] !== null) ? round((float) $row['max_val'], 2) : null,
'min' => ($row && $row['min_val'] !== null) ? round((float) $row['min_val'], 2) : null
];
}

private function getP95Peak(array $item, int $time_from, int $time_till): ?float {
$table = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64) ? 'trends_uint' : 'trends';

$sql_count = 'SELECT COUNT(*) AS cnt FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($time_from).
' AND clock<='.zbx_dbstr($time_till);

$row_count = \DBfetch(\DBselect($sql_count));
$total = ($row_count) ? (int) $row_count['cnt'] : 0;

if ($total === 0) {
return null;
}

$offset = max(0, (int) floor($total * 0.95) - 1);

$sql_p95 = 'SELECT value_max FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($time_from).
' AND clock<='.zbx_dbstr($time_till).
' ORDER BY value_max ASC';

$result = \DBselect($sql_p95, 1, $offset);
$row = \DBfetch($result);

return ($row && $row['value_max'] !== null) ? round((float) $row['value_max'], 2) : null;
}

private function calculateTrend(array $item, int $fw_start, int $fw_end, int $lw_start, int $lw_end): ?float {
$table = ((int) $item['value_type'] === ITEM_VALUE_TYPE_UINT64) ? 'trends_uint' : 'trends';

$min_hours = 24;

$sql_first = 'SELECT COUNT(*) AS cnt, AVG(value_avg) AS avg_val FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($fw_start).' AND clock<='.zbx_dbstr($fw_end);

$sql_last = 'SELECT COUNT(*) AS cnt, AVG(value_avg) AS avg_val FROM '.$table.
' WHERE itemid='.zbx_dbstr($item['itemid']).
' AND clock>='.zbx_dbstr($lw_start).' AND clock<='.zbx_dbstr($lw_end);

$row_first = \DBfetch(\DBselect($sql_first));
$row_last  = \DBfetch(\DBselect($sql_last));

if (!$row_first || !$row_last
|| (int) $row_first['cnt'] < $min_hours
|| (int) $row_last['cnt'] < $min_hours
|| $row_first['avg_val'] === null
|| $row_last['avg_val'] === null) {
return null;
}

return round((float) $row_last['avg_val'] - (float) $row_first['avg_val'], 2);
}

private function calculateRightSizing(array $r): array {
if ($r['cpu_p95'] !== null && $r['cpu_p95'] > 0 && $r['cpu_count'] !== null && $r['cpu_count'] > 0) {
$recommended = max(1, (int) floor($r['cpu_count'] * self::RIGHT_SIZE_FACTOR));
$actual_need = ($r['cpu_p95'] / 100) * $r['cpu_count'];

if ($recommended >= $actual_need && $recommended < $r['cpu_count']) {
$r['cpu_recommended'] = $recommended;
}
}

if ($r['ram_p95'] !== null && $r['ram_p95'] > 0 && $r['ram_total_gb'] !== null && $r['ram_total_gb'] > 0) {
$recommended = max(2, round($r['ram_total_gb'] * self::RIGHT_SIZE_FACTOR, 1));
$actual_need = ($r['ram_p95'] / 100) * $r['ram_total_gb'];

if ($recommended >= $actual_need && $recommended < $r['ram_total_gb']) {
$r['ram_recommended_gb'] = $recommended;
}
}

return $r;
}

private function generateRecommendation(array $r): string {
if ($r['cpu_avg'] === null || $r['ram_avg'] === null) {
return 'Insufficient data';
}

$growth_blocking = false;
$growth_parts = [];

if ($r['cpu_trend'] !== null && $r['cpu_trend'] > 0) {
$cpu_projected = $r['cpu_avg'] + $r['cpu_trend'];
if ($cpu_projected >= self::CPU_AVG_THRESHOLD) {
$growth_blocking = true;
$growth_parts[] = 'CPU: +'.$r['cpu_trend'].'% -> projected '.round($cpu_projected, 1).'%';
}
}
if ($r['ram_trend'] !== null && $r['ram_trend'] > 0) {
$ram_projected = $r['ram_avg'] + $r['ram_trend'];
if ($ram_projected >= self::RAM_AVG_THRESHOLD) {
$growth_blocking = true;
$growth_parts[] = 'RAM: +'.$r['ram_trend'].'% -> projected '.round($ram_projected, 1).'%';
}
}

$disk_saturated = ($r['disk_avg'] !== null && $r['disk_avg'] >= self::DISK_HIGH_THRESHOLD);
$net_high = ($r['net_in_avg'] !== null && $r['net_in_avg'] > 100000000)
|| ($r['net_out_avg'] !== null && $r['net_out_avg'] > 100000000);

$cpu_peak = $r['cpu_p95'] ?? $r['cpu_max'];
$ram_peak = $r['ram_p95'] ?? $r['ram_max'];

$cpu_avg_low = ($r['cpu_avg'] < self::CPU_AVG_THRESHOLD);
$ram_avg_low = ($r['ram_avg'] < self::RAM_AVG_THRESHOLD);
$cpu_peak_ok = ($cpu_peak !== null && $cpu_peak < self::CPU_MAX_THRESHOLD);
$ram_peak_ok = ($ram_peak !== null && $ram_peak < self::RAM_MAX_THRESHOLD);

if (!$cpu_avg_low || !$ram_avg_low) {
return 'Usage within acceptable range';
}

if ($disk_saturated || $net_high) {
return 'Low CPU/RAM but other resources high - no reduction';
}

if ($growth_blocking) {
return 'Workload growing toward thresholds ('.implode('; ', $growth_parts).') - no reduction';
}

if ($cpu_peak_ok && $ram_peak_ok) {
return 'Oversized - consider reducing CPU and memory';
}

$spike_parts = [];
if (!$cpu_peak_ok) {
$spike_parts[] = 'CPU P95: '.$cpu_peak.'%';
}
if (!$ram_peak_ok) {
$spike_parts[] = 'RAM P95: '.$ram_peak.'%';
}

return 'Mostly idle but periodic spikes ('.implode('; ', $spike_parts).')';
}

private function estimateAzureCost(int $cpu, float $ram, ?string $exact_sku = null): ?float {
if ($cpu <= 0 || $ram <= 0) return null;

$cache_file = sys_get_temp_dir() . '/zabbix_finops_azure_rates.json';
$cache_ttl = 86400; // 24 hours

$cache_data = ['general' => [], 'exact' => []];
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
$raw_cache = file_get_contents($cache_file);
if ($raw_cache !== false) {
$decoded = json_decode($raw_cache, true);
if (is_array($decoded)) {
if (isset($decoded['general'])) {
$cache_data = $decoded;
} else {
$cache_data['general'] = $decoded;
}
}
}
}

if (!empty($exact_sku)) {
if (isset($cache_data['exact'][$exact_sku])) {
return $cache_data['exact'][$exact_sku];
}

$url = "https://prices.azure.com/api/retail/prices?\$filter=" . urlencode("serviceName eq 'Virtual Machines' and priceType eq 'Consumption' and armSkuName eq '{$exact_sku}' and not contains(skuName, 'Spot') and not contains(skuName, 'Low Priority')");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 6);
curl_setopt($ch, CURLOPT_USERAGENT, 'ZabbixFinOpsToolkit/1.0');
$response = curl_exec($ch);
curl_close($ch);

if ($response) {
$data = json_decode($response, true);
if (isset($data['Items']) && is_array($data['Items']) && !empty($data['Items'])) {
$monthly_price = $data['Items'][0]['retailPrice'] * 730;
$cache_data['exact'][$exact_sku] = round($monthly_price, 2);
file_put_contents($cache_file, json_encode($cache_data));
return $cache_data['exact'][$exact_sku];
}
}
}

$rates = $cache_data['general'];
if (empty($rates)) {
$url = "https://prices.azure.com/api/retail/prices?\$filter=" . urlencode("serviceName eq 'Virtual Machines' and priceType eq 'Consumption' and armRegionName eq 'eastus' and contains(skuName, ' v5') and not contains(skuName, 'Spot') and not contains(skuName, 'Low Priority')");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 6);
curl_setopt($ch, CURLOPT_USERAGENT, 'ZabbixFinOpsToolkit/1.0');
$response = curl_exec($ch);
curl_close($ch);

if ($response) {
$data = json_decode($response, true);
if (isset($data['Items']) && is_array($data['Items'])) {
foreach ($data['Items'] as $item) {
if (isset($item['armSkuName']) && preg_match('/Standard_D(\d+)s?_?v5/i', $item['armSkuName'], $matches)) {
$sku_cpu = (int)$matches[1];
$monthly_price = $item['retailPrice'] * 730;

if (!isset($rates[$sku_cpu]) || $monthly_price < $rates[$sku_cpu]) {
$rates[$sku_cpu] = $monthly_price;
}
}
}
if (!empty($rates)) {
$cache_data['general'] = $rates;
file_put_contents($cache_file, json_encode($cache_data));
}
}
}
}

if (!empty($rates)) {
ksort($rates);
foreach ($rates as $sku_cpu => $monthly_price) {
$sku_ram = $sku_cpu * 4;
if ($sku_cpu >= $cpu && $sku_ram >= $ram) {
return round($monthly_price, 2);
}
}

$max_cpu = max(array_keys($rates));
$base_rate = $rates[$max_cpu] / $max_cpu;
return round($cpu * $base_rate, 2);
}

$estimated = ($cpu * 30.0) + ($ram * 4.0);
return round($estimated, 2);
}
}

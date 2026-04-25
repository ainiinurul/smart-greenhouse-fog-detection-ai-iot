<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ApiController extends Controller
{
    /**
     * Ambil data per greenhouse untuk tabel sensor (Pivot Format)
     */
    public function tablePerGH(Request $request)
    {
        // 1. Ambil Parameter Default
        $gh_id = $request->gh_id ?: 1;
        $page = 1;
        $perPage = 10;
        $sortField = 'recorded_at';
        $sortDirection = 'desc';
        $startDate = null;
        $endDate = null;
        $nodeId = null;

        if ($request->has('dict')) {
            $dict = json_decode($request->dict, true);
            $gh_id = $dict['gh_id'] ?? $gh_id;
            $page = $dict['page'] ?? $page;
            $perPage = $dict['per_page'] ?? $perPage;
            $sortField = $dict['sort_field'] ?? $sortField;
            $sortDirection = $dict['sort_direction'] ?? $sortDirection;
            $startDate = $dict['start_date'] ?? null;
            $endDate = $dict['end_date'] ?? null;
            $nodeId = $dict['node_id'] ?? null;
        }

        // 2. Mapping ID Sensor
        $sensors = DB::table('sensors')->where('gh_id', $gh_id)->get();
        $ids = [
            'temp' => $sensors->where('name', 'Temperature')->first()->id ?? 0,
            'hum' => $sensors->where('name', 'Humidity')->first()->id ?? 0,
            'light' => $sensors->where('name', 'Light Intensity')->first()->id ?? 0,
            'rssi' => $sensors->where('name', 'RSSI')->first()->id ?? 0,
        ];

        // 3. Optimasi: Ambil node_id & recorded_at yang terpaginasi terlebih dahulu.
        $baseQuery = DB::table('sensor_data as sd')
            ->select('sd.node_id', 'sd.recorded_at')
            ->whereIn('sd.sensor_id', array_values($ids))
            ->groupBy('sd.node_id', 'sd.recorded_at');

        if ($nodeId) {
            $baseQuery->where('sd.node_id', $nodeId);
        }

        if ($startDate && $endDate) {
            $baseQuery->whereBetween('sd.recorded_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        }

        // Handle Base Query Sorting
        $aggregateFields = [
            'temperature' => 'temp',
            'humidity' => 'hum',
            'light_intensity' => 'light',
            'rssi' => 'rssi'
        ];

        if (array_key_exists($sortField, $aggregateFields)) {
            $sensorKey = $aggregateFields[$sortField];
            $sensorIdToSort = $ids[$sensorKey];
            
            $baseQuery->leftJoin('sensor_data as sd_sort', function($join) use ($sensorIdToSort) {
                $join->on('sd.node_id', '=', 'sd_sort.node_id')
                     ->on('sd.recorded_at', '=', 'sd_sort.recorded_at')
                     ->where('sd_sort.sensor_id', '=', $sensorIdToSort);
            });
            
            $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';
            $baseQuery->orderBy(DB::raw("MAX(sd_sort.value)"), $direction);
        } elseif ($sortField === 'node_id' || $sortField === 'recorded_at' || $sortField === 'date' || $sortField === 'time') {
            $field = in_array($sortField, ['date', 'time']) ? 'sd.recorded_at' : "sd.$sortField";
            $baseQuery->orderBy($field, strtolower($sortDirection) === 'asc' ? 'asc' : 'desc');
        } else {
            $baseQuery->orderBy('sd.recorded_at', 'desc');
        }

        // Lakukan perhitungan Total secara super cepat via Cache & satu id sensor saja (Temperature)
        $cacheKey = "count_table_gh_{$gh_id}_" . md5(json_encode([$ids['temp'], $nodeId, $startDate, $endDate]));
        $totalRows = Cache::remember($cacheKey, 300, function () use ($ids, $nodeId, $startDate, $endDate) {
            $q = DB::table('sensor_data')->where('sensor_id', $ids['temp']);
            if ($nodeId) {
                $q->where('node_id', $nodeId);
            }
            if ($startDate && $endDate) {
                $q->whereBetween('recorded_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            }
            return $q->count();
        });

        // Ambil ID hanya di halaman ini (LIMIT OFFSET via Index)
        $baseItems = clone $baseQuery;
        $paginatedBaseItems = $baseItems->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $paginatedBase = new \Illuminate\Pagination\LengthAwarePaginator(
            $paginatedBaseItems,
            $totalRows,
            $perPage,
            $page
        );

        // Jika tidak ada data, kembalikan kosong
        if ($paginatedBase->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'total' => $paginatedBase->total(),
                'current_page' => $paginatedBase->currentPage(),
                'per_page' => $perPage,
                'last_page' => $paginatedBase->lastPage(),
            ]);
        }

        // 4. Kumpulkan hasil node_id dan recorded_at yang masuk di page ini
        $nodesAndDates = [];
        foreach ($paginatedBase->items() as $item) {
            $nodesAndDates[] = ['node_id' => $item->node_id, 'recorded_at' => $item->recorded_at];
        }

        // 5. Query nilai aggregate hanya untuk row yang ada di halaman ini
        $aggregatedQuery = DB::table('sensor_data')
            ->whereIn('sensor_id', array_values($ids))
            ->where(function ($q) use ($nodesAndDates) {
                foreach ($nodesAndDates as $group) {
                    $q->orWhere(function ($subq) use ($group) {
                        $subq->where('node_id', $group['node_id'])
                            ->where('recorded_at', $group['recorded_at']);
                    });
                }
            })
            ->select(
                'node_id',
                'recorded_at',
                DB::raw("DATE_FORMAT(recorded_at, '%d-%m-%Y') as date"),
                DB::raw("TIME(recorded_at) as time"),
                DB::raw("MAX(CASE WHEN sensor_id = {$ids['temp']} THEN value END) as temperature"),
                DB::raw("MAX(CASE WHEN sensor_id = {$ids['hum']} THEN value END) as humidity"),
                DB::raw("MAX(CASE WHEN sensor_id = {$ids['light']} THEN value END) as light_intensity"),
                DB::raw("MAX(CASE WHEN sensor_id = {$ids['rssi']} THEN value END) as rssi")
            )
            ->groupBy('node_id', 'recorded_at');

        $aggregatedData = $aggregatedQuery->get();

        // Gabungkan/urutkan hasilnya kembali sesuai order paginasi
        $finalData = [];
        foreach ($nodesAndDates as $nd) {
            $match = $aggregatedData->first(fn($a) => $a->node_id === $nd['node_id'] && $a->recorded_at === $nd['recorded_at']);
            if ($match) {
                $finalData[] = $match;
            }
        }

        // Data telah disortir secara global di level database, tidak perlu lokal sorting.

        return response()->json([
            'success' => true,
            'data' => $finalData,
            'total' => $paginatedBase->total(),
            'current_page' => $paginatedBase->currentPage(),
            'per_page' => $perPage,
            'last_page' => $paginatedBase->lastPage(),
        ]);
    }

    /**
     * Ambil data rekaman kamera (Fix Status & Image URL)
     */
    public function cameraPerGH(Request $request)
    {
        $gh_id = $request->gh_id ?: 1;
        $perPage = 5;
        $page = 1;

        if ($request->has('dict')) {
            $dict = json_decode($request->dict, true);
            $gh_id = $dict['gh_id'] ?? $gh_id;
            $perPage = $dict['per_page'] ?? $perPage;
            $page = $dict['page'] ?? $page;
        }

        $paginated = DB::table('camera_data')
            ->where('gh_id', $gh_id)
            ->orderBy('recorded_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Mapping URL Gambar (Dinamis sesuai host yang mengakses)
        $domain = rtrim(url('/'), '/');

        $items = collect($paginated->items())->map(function ($item) use ($domain) {
            // FIX STATUS: Frontend butuh kolom "status" (Teks), bukan "isFoggy" (Boolean)
            $item->status = $item->isFoggy ? 'Berkabut' : 'Tidak Berkabut';

            // FIX IMAGE: Gabungkan Domain dengan Path dari DB
            if (!empty($item->image) && !str_starts_with($item->image, 'http')) {
                // Media Base URL untuk akses gambar (bisa diarahkan ke https://ta.atomic.web.id)
                $mediaBase = rtrim(config('app.media_url', url('/')), '/');
                $itemPath = $item->image;

                // Bersihkan 'public/' jika ada
                $itemPath = str_replace('public/', '', $itemPath);

                // Pastikan diawali satu slash agar konsisten
                $itemPath = '/' . ltrim($itemPath, '/');

                // Jika sudah diawali '/storage/', gunakan apa adanya
                if (str_starts_with($itemPath, '/storage/')) {
                    $item->image = $mediaBase . $itemPath;
                } else {
                    // Jika belum, tambahkan '/storage/'
                    $item->image = $mediaBase . '/storage' . $itemPath;
                }
            }

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'total' => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ]);
    }

    /**
     * Ambil data untuk Gauge (Monitoring) 
     */
    public function get_average_sensor_data(Request $request)
    {
        $gh_id = $request->gh_id ?: 1;

        // Mencegah FULL TABLE SCAN `sensor_data` yang berat jika `sensor_snapshots` sudah ada
        if (!Cache::remember('sensor_snapshots_initialized', 86400, fn() => DB::table('sensor_snapshots')->exists())) {
            try {
                DB::statement("
                    INSERT INTO sensor_snapshots (sensor_id, node_id, value, recorded_at, created_at, updated_at)
                    SELECT sd.sensor_id, sd.node_id, sd.value, sd.recorded_at, NOW(), NOW()
                    FROM sensor_data sd
                    INNER JOIN (
                        SELECT sensor_id, node_id, MAX(id) AS latest_id
                        FROM sensor_data
                        GROUP BY sensor_id, node_id
                    ) latest ON latest.latest_id = sd.id
                    ON DUPLICATE KEY UPDATE
                        value = VALUES(value),
                        recorded_at = VALUES(recorded_at),
                        updated_at = VALUES(updated_at)
                ");
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Snapshot Error: " . $e->getMessage());
            }
            Cache::put('sensor_snapshots_initialized', true, 86400);

            Cache::forget('gaugeData');
            Cache::forget('monitoring_latest_time');
            Cache::forget('heatmap_sensor_data');
            Cache::forget('heatmap_latest_time');
        }

        $snapshot = DB::table('sensor_snapshots as ss')
            ->join('sensors as s', 's.id', '=', 'ss.sensor_id')
            ->where('s.gh_id', $gh_id)
            ->where('ss.recorded_at', '>=', Carbon::today())
            ->select('s.name', DB::raw('AVG(ss.value) as avg_value'), DB::raw('MAX(ss.recorded_at) as last_recorded_at'))
            ->groupBy('s.name')
            ->get();

        $result = [
            'temperature' => 0,
            'humidity' => 0,
            'light_intensity' => 0,
            'last_recorded_at' => null,
        ];

        foreach ($snapshot as $row) {
            $key = strtolower(str_replace(' ', '_', $row->name));
            if (array_key_exists($key, $result)) {
                $result[$key] = round($row->avg_value, 2);
                if (!$result['last_recorded_at'] || $row->last_recorded_at > $result['last_recorded_at']) {
                    $result['last_recorded_at'] = $row->last_recorded_at;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * Ambil data untuk Chart (Grafik)
     */
    public function fetchChart(Request $request)
    {
        $sensor_id = $request->sensor_id;
        $mode = 'avg';
        $range = 'today';
        $date_start = null;
        $date_end = null;
        $time = null;

        if ($request->has('dict')) {
            $dict = json_decode($request->dict, true);
            $sensor_id = $dict['sensor_id'] ?? $sensor_id;
            $mode = $dict['mode'] ?? $mode;
            $range = $dict['range'] ?? $range;
            $date_start = $dict['date_start'] ?? null;
            $date_end = $dict['date_end'] ?? null;
            $time = $dict['time'] ?? null;
        }

        if (!$sensor_id) {
            return response()->json(['success' => false, 'message' => 'Sensor ID required'], 400);
        }

        $query = DB::table('sensor_data')->where('sensor_id', $sensor_id);

        // Filter based on range
        if ($range === 'custom') {
            if ($date_start && $date_end) {
                $query->whereBetween('recorded_at', [$date_start . ' 00:00:00', $date_end . ' 23:59:59']);
            } elseif ($date_start) {
                $query->where('recorded_at', '>=', $date_start . ' 00:00:00');
            }

            if ($time) {
                $query->whereRaw("TIME_FORMAT(recorded_at, '%H:00') = ?", [$time]);
            }
        } else {
            $sub = match ($range) {
                'last_1h' => Carbon::now()->subHour(),
                'last_1w' => Carbon::now()->subWeek(),
                'last_1m' => Carbon::now()->subMonth(),
                'today' => Carbon::today(), // Mulai dari jam 00:00:00 hari ini
                default => Carbon::now()->subDay(),
            };
            $query->where('recorded_at', '>=', $sub);
        }

        // Determine bucket type for grouping
        $diffHours = $range === 'custom' ? 24 : match ($range) {
            'last_1h' => 1,
            'today' => 24,
            'last_1w' => 168,
            'last_1m' => 720,
            default => 24,
        };

        if ($diffHours <= 1) {
            $dateFormat = '%H:%i';
            $bucketType = 'minute';
        } elseif ($diffHours <= 48) {
            $dateFormat = '%H:00';
            $bucketType = 'hour';
        } else {
            $dateFormat = '%Y-%m-%d';
            $bucketType = 'day';
        }

        if ($mode === 'per_node') {
            $rawData = $query->select(
                'node_id',
                DB::raw("DATE_FORMAT(recorded_at, '$dateFormat') as label"),
                DB::raw('AVG(value) as value'),
                DB::raw('MIN(recorded_at) as earliest')
            )
                ->groupBy('node_id', 'label')
                ->orderBy('earliest', 'asc')
                ->get();

            // Extract labels in chronological order from raw data
            $labels = $rawData->sortBy('earliest')->pluck('label')->unique()->values();
            $nodes = $rawData->pluck('node_id')->unique();
            $datasets = [];

            foreach ($nodes as $nodeId) {
                $nodeData = [];
                foreach ($labels as $lbl) {
                    $match = $rawData->where('node_id', $nodeId)->where('label', $lbl)->first();
                    $nodeData[] = $match ? round($match->value, 2) : null;
                }
                $datasets[] = [
                    'node_id' => $nodeId,
                    'label' => "Node $nodeId",
                    'data' => $nodeData
                ];
            }

            return response()->json([
                'success' => true,
                'mode' => 'per_node',
                'raw_labels' => $labels,
                'bucket_type' => $bucketType,
                'datasets' => $datasets
            ]);
        }

        // Mode: Average (default)
        $data = $query->select(
            DB::raw("DATE_FORMAT(recorded_at, '$dateFormat') as label"),
            DB::raw('AVG(value) as value'),
            DB::raw('MIN(recorded_at) as earliest')
        )
            ->groupBy('label')
            ->orderBy('earliest', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'mode' => 'avg',
            'data' => $data->pluck('value')->map(fn($v) => round($v, 2)),
            'raw_labels' => $data->pluck('label'),
            'bucket_type' => $bucketType
        ]);
    }

    /**
     * Simpan data sensor dari Hardware
     */
    public function saveSensorData(Request $request)
    {
        $request->validate([
            'gh_id' => 'required|integer',
            'node_id' => 'required|integer',
        ]);

        $gh_id = $request->gh_id;
        $node_id = $request->node_id;
        $recorded_at = $request->recorded_at ?: now();

        // Mapping Sensor ID secara dinamis (mencegah error jika ID di server berbeda)
        $sensors = DB::table('sensors')->where('gh_id', $gh_id)->get();
        $mapping = [
            'temperature' => $sensors->where('name', 'Temperature')->first()->id ?? 0,
            'humidity' => $sensors->where('name', 'Humidity')->first()->id ?? 0,
            'light_intensity' => $sensors->where('name', 'Light Intensity')->first()->id ?? 0,
            'rssi' => $sensors->where('name', 'RSSI')->first()->id ?? 0,
        ];

        $insertedCount = 0;
        $aliases = [
            'temperature' => ['temperature', 'temp'],
            'humidity' => ['humidity', 'hum'],
            'light_intensity' => ['light_intensity', 'light', 'lux'],
            'rssi' => ['rssi'],
        ];

        foreach ($mapping as $key => $sensor_id) {
            $value = null;
            foreach ($aliases[$key] as $alias) {
                if ($request->has($alias)) {
                    $value = $request->input($alias);
                    break;
                }
            }

            if ($value !== null) {
                // Insert ke sensor_data
                DB::table('sensor_data')->insert([
                    'sensor_id' => $sensor_id,
                    'node_id' => $node_id,
                    'value' => $value,
                    'recorded_at' => $recorded_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Update sensor_snapshots (Upsert)
                DB::table('sensor_snapshots')->updateOrInsert(
                    ['sensor_id' => $sensor_id, 'node_id' => $node_id],
                    [
                        'value' => $value,
                        'recorded_at' => $recorded_at,
                        'updated_at' => now(),
                    ]
                );
                $insertedCount++;
            }
        }

        if ($insertedCount > 0) {
            // Bersihkan cache agar tampilan web langsung update
            Cache::forget('gaugeData');
            Cache::forget('monitoring_latest_time');
            Cache::forget('heatmap_sensor_data');
            Cache::forget('heatmap_latest_time');
            Cache::forget('heatmap_thresholds');
        }

        return response()->json(['success' => true, 'inserted' => $insertedCount]);
    }

    /**
     * Simpan data kamera dari Hardware
     */
    public function saveCameraData(Request $request)
    {
        $gh_id = $request->gh_id;
        $isFoggy = $request->isFoggy;
        $confidence = $request->confidence;
        $recorded_at = $request->recorded_at ?: now();
        $image_base64 = $request->image;

        if (!$image_base64) {
            return response()->json(['success' => false, 'message' => 'No image provided'], 400);
        }

        // Handle Base64 Image
        if (preg_match('/^data:image\/(\w+);base64,/', $image_base64, $type)) {
            $image_base64 = substr($image_base64, strpos($image_base64, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, etc
        } else {
            $type = 'jpg';
        }

        $image_base64 = base64_decode($image_base64);
        if ($image_base64 === false) {
            return response()->json(['success' => false, 'message' => 'Invalid base64 data'], 400);
        }

        $fileName = 'camera_' . time() . '_' . rand(100, 999) . '.' . $type;
        $folderPath = 'public/camera/';
        $dbPath = 'storage/camera/' . $fileName;

        if (!\Illuminate\Support\Facades\Storage::exists($folderPath)) {
            \Illuminate\Support\Facades\Storage::makeDirectory($folderPath);
        }

        \Illuminate\Support\Facades\Storage::put($folderPath . $fileName, $image_base64);

        DB::table('camera_data')->insert([
            'gh_id' => $gh_id,
            'image' => $dbPath,
            'isFoggy' => $isFoggy,
            'confidence' => $confidence,
            'recorded_at' => $recorded_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $isFoggyNormalized = filter_var($isFoggy, FILTER_VALIDATE_BOOLEAN);

        if ($isFoggyNormalized) {
            \Illuminate\Support\Facades\Log::info("Triggering FCM for GH {$gh_id}. isFoggy=" . json_encode($isFoggy));
            try {
                $messaging = app('firebase.messaging');
                $message = \Kreait\Firebase\Messaging\CloudMessage::fromArray([
                    'topic' => 'peringatan_kabut',
                    'notification' => [
                        'title' => 'Peringatan Kabut',
                        'body' => 'Terdeteksi kabut pada kamera!',
                    ],
                ]);

                $messaging->send($message);
                \Illuminate\Support\Facades\Log::info("FCM sent successfully for GH {$gh_id}");
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('FCM Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
        } else {
            \Illuminate\Support\Facades\Log::info("FCM NOT triggered for GH {$gh_id}. isFoggy=" . json_encode($isFoggy));
        }

        return response()->json(['success' => true]);
    }

    /**
     * Update ambang batas sensor dari Web
     */
    public function updateThresholds(Request $request)
    {
        $thresholds = $request->thresholds; // Array of {sensor_id, min, max}
        if (!is_array($thresholds)) {
            return response()->json(['success' => false, 'message' => 'Invalid data format'], 400);
        }

        foreach ($thresholds as $t) {
            DB::table('sensors')
                ->where('id', $t['sensor_id'])
                ->update([
                    'threshold_min' => $t['threshold_min'],
                    'threshold_max' => $t['threshold_max'],
                    'updated_at' => now(),
                ]);
        }

        Cache::forget('heatmap_thresholds');
        Cache::forget('controlling_data');

        return response()->json(['success' => true]);
    }

    public function getDeviceStatus(Request $request)
    {
        $gh_id = $request->gh_id ?: 1;
        $data = DB::table('device_statuses')->where('gh_id', $gh_id)->first();
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function postDeviceStatus(Request $request)
    {
        $gh_id = $request->gh_id;
        $fields = $request->only(['exhaust_status', 'dehumidifier_status', 'blower_status']);

        DB::table('device_statuses')->updateOrInsert(
            ['gh_id' => $gh_id],
            array_merge($fields, ['updated_at' => now()])
        );

        return response()->json(['success' => true, 'data' => DB::table('device_statuses')->where('gh_id', $gh_id)->first()]);
    }

    public function getControlling()
    {
        $data = \App\Models\Greenhouse::with('sensor')->get();
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function thd()
    {
        return response()->json(['success' => true, 'message' => 'THD endpoint active']);
    }

    public function camera_status()
    {
        return response()->json(['success' => true, 'message' => 'Camera status active']);
    }
}

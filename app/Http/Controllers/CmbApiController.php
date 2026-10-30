<?php

namespace App\Http\Controllers;

use App\Services\TokenEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CmbApiController extends Controller
{
    /**
     * Cache key for list of all internal pegawai NIPs
     */
    public const CACHE_KEY_PEGAWAI_NIPS = 'cmb_all_pegawai_nips';

    /**
     * Memoized NIPs for current request lifecycle
     */
    private static ?array $memoizedPegawaiNips = null;

    private string $baseUrl;
    private string $apiToken;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.cmb.api_url', ''), '/');
        $this->apiToken = config('services.cmb.api_token', '');
    }

    /**
     * Get list of all pegawai NIPs from CMB API with caching.
     *
     * @param bool $fresh If true, bypass cache and fetch fresh from API
     * @return string[] Array of NIP strings
     */
    public static function getAllPegawaiNips(bool $fresh = false): array
    {
        if (!$fresh && self::$memoizedPegawaiNips !== null) {
            return self::$memoizedPegawaiNips;
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY_PEGAWAI_NIPS);
        }

        $cached = Cache::get(self::CACHE_KEY_PEGAWAI_NIPS);
        if (is_array($cached) && !empty($cached)) {
            self::$memoizedPegawaiNips = $cached;
            return self::$memoizedPegawaiNips;
        }

        try {
            $controller = app(self::class);
            $request = new Request([
                'with_pagination' => 'false',
                'include_json'    => 'false',
                'status'          => '',
            ]);

            $response = $controller->getPegawai($request);
            if ($response->getStatusCode() === 200) {
                $content = json_decode($response->getContent(), true);
                $items = $content['data'] ?? [];

                $result = [];
                foreach ($items as $item) {
                    $nipVal = trim((string)($item['nip'] ?? ''));
                    if ($nipVal !== '') {
                        $result[] = $nipVal;
                    }
                }

                $nips = array_values(array_unique($result));
                if (!empty($nips)) {
                    Cache::put(self::CACHE_KEY_PEGAWAI_NIPS, $nips, 3600);
                    self::$memoizedPegawaiNips = $nips;
                    return self::$memoizedPegawaiNips;
                }
            } else {
                Log::warning('CMB getPegawai returned non-200 status ' . $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            Log::error('Error fetching pegawai NIPs in getAllPegawaiNips: ' . $e->getMessage());
        }

        self::$memoizedPegawaiNips = [];
        return self::$memoizedPegawaiNips;
    }

    /**
     * Determine whether a given NIP belongs to an internal employee based on CMB API pegawai data.
     *
     * @param string|null $nip
     * @return bool
     */
    public static function isInternalNip(?string $nip): bool
    {
        $nip = trim((string) $nip);
        if ($nip === '' || $nip === '-' || strtolower($nip) === 'umum' || strtolower($nip) === 'null') {
            return false;
        }

        $internalNips = self::getAllPegawaiNips();
        if (!empty($internalNips)) {
            return in_array($nip, $internalNips, true);
        }

        // Graceful fallback to 18-digit check if CMB API is unreachable or returned empty
        return (bool) preg_match('/^\d{18}$/', $nip);
    }


    /**
     * Generate SSO token
     * GET /sso/generate/{identifier}
     */
    public function generateSsoToken(Request $request, string $identifier)
    {
        try {
            $expMinutes = $request->query('exp_minutes', 60);

            $url = "{$this->baseUrl}/sso/generate/" . urlencode($identifier) . "?exp_minutes={$expMinutes}";

            $headers = $this->buildHeaders();

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'verify' => false, // Adjust based on your SSL requirements
                ])
                ->get($url);

            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
        } catch (\Exception $e) {
            Log::error('Error generating SSO token: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to generate SSO token',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify SSO token
     * GET /sso/verify/{token}
     */
    public function verifySsoToken(Request $request, string $token)
    {
        try {
            $url = "{$this->baseUrl}/sso/verify/" . urlencode($token);

            $headers = $this->buildHeaders();

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'verify' => false,
                ])
                ->get($url);

            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
        } catch (\Exception $e) {
            Log::error('Error verifying SSO token: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to verify SSO token',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all pegawai
     * GET /api/pegawai
     */
    public function getPegawai(Request $request)
    {
        try {
            $includeJson = $request->query('include_json', 'false');
            $withPagination = $request->query('with_pagination', 'false');
            $unitOrganisasiId = $request->query('unit_organisasi_id', null);
            $withPegawai360 = $request->query('with_pegawai_360', 'false');
            $jabatan = $request->query('jabatan', null);
            $nip = $request->query('nip', null);
            $q = $request->query('q', null);
            $status = $request->query('status', 'ACTIVE');
            $fetchAll = $request->boolean('fetch_all', false);

            $isNoPagination = ($withPagination === 'false' || $withPagination === false || $fetchAll);
            $perPage = $isNoPagination ? $request->query('per_page', 5000) : $request->query('per_page', 15);
            $page = $request->query('page', 1);

            $url = "{$this->baseUrl}/api/pegawai?include_json={$includeJson}&with_pagination={$withPagination}&per_page={$perPage}&page={$page}";
            if ($unitOrganisasiId) {
                $url .= "&unit_organisasi_id={$unitOrganisasiId}";
            }
            if ($nip) {
                $url .= "&nip={$nip}";
            }
            if ($q) {
                $url .= "&q=" . urlencode($q);
            }
            if ($withPegawai360) {
                $url .= "&with_pegawai_360={$withPegawai360}";
            }
            if ($jabatan) {
                $url .= "&jabatan=" . urlencode($jabatan);
            }
            if ($status) {
                $url .= "&status=" . urlencode($status);
            }

            $headers = $this->buildHeaders();

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'verify' => false,
                ])
                ->timeout(60)
                ->get($url);

            if (!$response->successful()) {
                return response($response->body(), $response->status())
                    ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
            }

            $data = $response->json();

            // If unpaginated is requested and external API returned paginated metadata with remaining pages, fetch all pages
            if ($isNoPagination && is_array($data) && isset($data['data'])) {
                $items = $data['data'];
                $meta = $data['meta'] ?? [];
                $lastPage = $meta['last_page'] ?? 1;
                $total = $meta['total'] ?? count($items);

                if ($lastPage > 1 && count($items) < $total) {
                    for ($p = 2; $p <= $lastPage; $p++) {
                        $nextUrl = preg_replace('/([?&])page=\d+/', '${1}page=' . $p, $url);
                        $nextRes = Http::withHeaders($headers)
                            ->withOptions(['verify' => false])
                            ->timeout(30)
                            ->get($nextUrl);

                        if ($nextRes->successful()) {
                            $nextJson = $nextRes->json();
                            $nextItems = $nextJson['data'] ?? [];
                            if (is_array($nextItems) && !empty($nextItems)) {
                                $items = array_merge($items, $nextItems);
                            }
                        }
                    }
                    $data['data'] = $items;
                    if (isset($data['meta']) && is_array($data['meta'])) {
                        $data['meta']['current_page'] = 1;
                        $data['meta']['last_page'] = 1;
                        $data['meta']['per_page'] = count($items);
                        $data['meta']['total'] = count($items);
                    }
                }

                return response()->json($data);
            }

            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
        } catch (\Exception $e) {
            Log::error('Error fetching pegawai: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch pegawai',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific pegawai by NIP
     * GET /api/pegawai/{nip}
     */
    public function getPegawaiByNip(Request $request, string $nip)
    {
        try {
            $withUnitParent = $request->query('with_unit_parent', 'false');
            $url = "{$this->baseUrl}/api/pegawai/" . urlencode($nip) . "?with_unit_parent={$withUnitParent}";

            $headers = $this->buildHeaders();
            $headers['Content-Type'] = 'application/json';

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'verify' => false,
                ])
                ->get($url);

            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
        } catch (\Exception $e) {
            Log::error('Error fetching pegawai by NIP: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch pegawai',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch calendar data
     * GET /calendar/fetch
     */
    public function fetchCalendar(Request $request)
    {
        try {
            $period = $request->query('period', '');

            $url = "{$this->baseUrl}/calendar/fetch?period={$period}";

            $headers = $this->buildHeaders();
            $headers['Accept'] = 'application/json';

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'verify' => false,
                ])
                ->get($url);

            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
        } catch (\Exception $e) {
            Log::error('Error fetching calendar: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch calendar',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Build headers with encrypted API token
     * 
     * @return array
     */
    private function buildHeaders(): array
    {
        $headers = [];

        if (!empty($this->apiToken)) {
            $encryptedToken = TokenEncryptionService::encryptTokenForHeader(
                $this->apiToken,
                ['salt' => $this->apiToken]
            );
            $headers['X-Api-Token'] = $encryptedToken;
            $headers['origin'] = config('app.url', 'http://localhost');
        }

        return $headers;
    }

    public function getUnitOrganisasi(Request $request)
    {
        try {
            $url = "{$this->baseUrl}/api/unit-organisasi";

            $headers = $this->buildHeaders();
            $headers['Accept'] = 'application/json';

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'verify' => false,
                ])
                ->get($url);

            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
        } catch (\Exception $e) {
            Log::error('Error fetching unit organisasi: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch unit organisasi',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getJabatan()
    {
        try {
            $url = "{$this->baseUrl}/api/jabatan";

            $headers = $this->buildHeaders();
            $headers['Accept'] = 'application/json';

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'verify' => false,
                ])
                ->get($url);

            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
        } catch (\Exception $e) {
            Log::error('Error fetching jabatan: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch jabatan',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

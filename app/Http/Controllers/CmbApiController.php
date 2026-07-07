<?php

namespace App\Http\Controllers;

use App\Services\TokenEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CmbApiController extends Controller
{
    private string $baseUrl;
    private string $apiToken;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.cmb.api_url', ''), '/');
        $this->apiToken = config('services.cmb.api_token', '');
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
            $perPage = $request->query('per_page', 15);
            $page = $request->query('page', 1);
            $nip = $request->query('nip', null);
            $q = $request->query('q', null);
            
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
            
            $headers = $this->buildHeaders();
            
            $response = Http::withHeaders($headers)
                ->withOptions([
                    'verify' => false,
                ])
                ->get($url);

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
}

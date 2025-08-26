<?php

namespace App\Http\Controllers\API\Patient;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\HealthMetric;

class HealthMetricsController extends Controller
{
    /**
     * Store a new health metric
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string',
            'value' => 'required|string',
            'unit' => 'required|string',
            'measured_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'custom_type' => 'required_if:type,custom|nullable|string',
            'source' => 'nullable|string|in:manual,report,device',
            'context' => 'nullable|string|in:fasting,after_meal,before_meal,resting,after_exercise,before_sleep,morning,evening,general,medical_test'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // ✨ NEW: Get target profile ID from header or use authenticated user
        $targetProfileId = $request->header('X-Active-Profile-ID') ?: auth()->id();

        // ✨ NEW: Validate profile access
        $currentUser = auth()->user();
        if (!$currentUser->canManageProfile($targetProfileId)) {
            return response()->json(['error' => 'Permission denied for this profile'], 403);
        }

        $patientId = $targetProfileId;
        
        $metric = new HealthMetric([
            'patient_id' => $patientId,
            'type' => $request->type,
            'custom_type' => $request->custom_type,
            'value' => $request->value,
            'unit' => $request->unit,
            'measured_at' => $request->measured_at ?? now(),
            'notes' => $request->notes,
            'source' => $request->source ?? 'manual',
            'context' => $request->context
        ]);
        
        // Auto-populate category and subcategory
        $metric->setMetricCategories();
        
        // Calculate status
        $metric->status = $metric->calculateStatus();
        
        $metric->save();
        
        return response()->json([
            'message' => 'Health metric recorded successfully',
            'metric' => $metric
        ], 201);
    }
    
    /**
     * Enhanced: Get all metrics for the authenticated patient
     * Returns data grouped by metric type with enhanced metadata
     */
    public function index(Request $request)
    {
        // ✨ NEW: Get target profile ID from header or use authenticated user
        $targetProfileId = $request->header('X-Active-Profile-ID') ?: auth()->id();

        // ✨ NEW: Validate profile access
        $currentUser = auth()->user();
        if (!$currentUser->canManageProfile($targetProfileId)) {
            return response()->json(['error' => 'Permission denied for this profile'], 403);
        }

        $patientId = $targetProfileId;
        
        // Build filters from request
        $filters = array_filter([
            'type' => $request->input('type'),
            'category' => $request->input('category'),
            'subcategory' => $request->input('subcategory'),
            'status' => $request->input('status'),
            'source' => $request->input('source'),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
        ]);
        
        // Get metrics with enhanced metadata
        $groupedMetrics = HealthMetric::getPatientMetricsWithMetadata($patientId, $filters);
        
        // Add summary statistics
        $totalMetrics = 0;
        $recentMetrics = 0;
        $categorySummary = [];
        $sourceSummary = ['manual' => 0, 'report' => 0, 'device' => 0];
        
        foreach ($groupedMetrics as $type => $metrics) {
            $totalMetrics += count($metrics);
            
            foreach ($metrics as $metric) {
                // Count recent metrics (for review badges)
                if ($metric['is_recent']) {
                    $recentMetrics++;
                }
                
                // Count by category
                if ($metric['category']) {
                    $categorySummary[$metric['category']] = ($categorySummary[$metric['category']] ?? 0) + 1;
                }
                
                // Count by source
                $sourceSummary[$metric['source']] = ($sourceSummary[$metric['source']] ?? 0) + 1;
            }
        }
        
        // Return enhanced response
        return response()->json([
            // Main data (grouped metrics)
            'metrics' => $groupedMetrics,
            
            // Summary statistics
            'summary' => [
                'total_metrics' => $totalMetrics,
                'recent_metrics' => $recentMetrics,
                'metrics_by_category' => $categorySummary,
                'metrics_by_source' => $sourceSummary,
                'unique_metric_types' => count($groupedMetrics),
            ],
            
            // UI hints
            'ui_hints' => [
                'has_new_metrics' => $recentMetrics > 0,
                'show_review_badges' => $recentMetrics > 0,
                'has_report_metrics' => $sourceSummary['report'] > 0,
                'categories_available' => array_keys($categorySummary),
            ],
            
            // Metadata
            'filters_applied' => $filters,
            'generated_at' => now()->toISOString(),
        ]);
    }
    
    /**
     * Enhanced: Get recent health metrics summary
     * Useful for showing "What's New" sections
     */
    public function getRecentMetrics(Request $request)
    {
        // ✨ NEW: Get target profile ID from header or use authenticated user
        $targetProfileId = $request->header('X-Active-Profile-ID') ?: auth()->id();

        // ✨ NEW: Validate profile access
        $currentUser = auth()->user();
        if (!$currentUser->canManageProfile($targetProfileId)) {
            return response()->json(['error' => 'Permission denied for this profile'], 403);
        }

        $patientId = $targetProfileId;
        $days = $request->input('days', 7); // Default to last 7 days
        
        $recentMetrics = HealthMetric::where('patient_id', $patientId)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Group by date and source
        $groupedByDate = $recentMetrics->groupBy(function($metric) {
            return $metric->created_at->format('Y-m-d');
        });
        
        $groupedBySource = $recentMetrics->groupBy('source');
        
        // Enhanced timeline formatting
        $timeline = [];
        foreach ($groupedByDate as $date => $metrics) {
            $reportMetrics = $metrics->where('source', 'report');
            $manualMetrics = $metrics->where('source', 'manual');
            
            if ($reportMetrics->count() > 0) {
                $timeline[] = [
                    'date' => $date,
                    'type' => 'report_extraction',
                    'title' => 'Medical Report Analysis',
                    'subtitle' => $reportMetrics->count() . ' new health metrics extracted',
                    'metrics' => $reportMetrics->map(function($metric) {
                        return [
                            'type' => $metric->type,
                            'display_name' => HealthMetric::getDisplayName($metric->type),
                            'value' => $metric->value,
                            'unit' => $metric->unit,
                            'status' => $metric->status,
                        ];
                    })->values(),
                    'icon' => 'document-text',
                    'color' => '#38BFA7',
                    'created_at' => $reportMetrics->first()->created_at->toISOString(),
                ];
            }
            
            if ($manualMetrics->count() > 0) {
                $timeline[] = [
                    'date' => $date,
                    'type' => 'manual_entry',
                    'title' => 'Manual Entries',
                    'subtitle' => $manualMetrics->count() . ' metrics added manually',
                    'metrics' => $manualMetrics->map(function($metric) {
                        return [
                            'type' => $metric->type,
                            'display_name' => HealthMetric::getDisplayName($metric->type),
                            'value' => $metric->value,
                            'unit' => $metric->unit,
                            'status' => $metric->status,
                        ];
                    })->values(),
                    'icon' => 'create',
                    'color' => '#2C7BE5',
                    'created_at' => $manualMetrics->first()->created_at->toISOString(),
                ];
            }
        }
        
        // Sort timeline by creation time (newest first)
        usort($timeline, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return response()->json([
            'timeline' => $timeline,
            'summary' => [
                'total_recent' => $recentMetrics->count(),
                'from_reports' => $groupedBySource->get('report', collect())->count(),
                'manual_entries' => $groupedBySource->get('manual', collect())->count(),
                'days_covered' => $days,
                'latest_extraction' => $groupedBySource->get('report', collect())->first()?->created_at?->toISOString(),
            ],
        ]);
    }
    
    /**
     * Mark metrics as reviewed (remove review badges)
     */
    public function markAsReviewed(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'metric_ids' => 'required|array',
            'metric_ids.*' => 'integer|exists:health_metrics,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // ✨ NEW: Get target profile ID from header or use authenticated user
        $targetProfileId = $request->header('X-Active-Profile-ID') ?: auth()->id();

        // ✨ NEW: Validate profile access
        $currentUser = auth()->user();
        if (!$currentUser->canManageProfile($targetProfileId)) {
            return response()->json(['error' => 'Permission denied for this profile'], 403);
        }

        $patientId = $targetProfileId;
        
        // Update metrics to add "reviewed" note
        $updatedCount = HealthMetric::where('patient_id', $patientId)
            ->whereIn('id', $request->metric_ids)
            ->update([
                'notes' => \DB::raw("CONCAT(COALESCE(notes, ''), IF(notes IS NOT NULL AND notes != '', ' | ', ''), 'Reviewed by patient on " . now()->format('Y-m-d') . "')")
            ]);
        
        return response()->json([
            'message' => 'Metrics marked as reviewed successfully',
            'updated_count' => $updatedCount,
        ]);
    }
    
    /**
     * Get stats and trends for a specific metric type
     */
    public function trends(Request $request, $type)
    {
        // ✨ NEW: Get target profile ID from header or use authenticated user
        $targetProfileId = $request->header('X-Active-Profile-ID') ?: auth()->id();

        // ✨ NEW: Validate profile access
        $currentUser = auth()->user();
        if (!$currentUser->canManageProfile($targetProfileId)) {
            return response()->json(['error' => 'Permission denied for this profile'], 403);
        }

        $patientId = $targetProfileId;
        
        // Validate type
        if (empty($type) || strlen($type) > 100) {
            return response()->json(['error' => 'Invalid parameter type'], 400);
        }
        
        $timeframe = $request->input('timeframe', 'month'); // day, week, month, year
        
        // Get appropriate date based on timeframe
        $fromDate = now();
        switch ($timeframe) {
            case 'day':
                $fromDate = $fromDate->subDay();
                break;
            case 'week':
                $fromDate = $fromDate->subWeek();
                break;
            case 'month':
                $fromDate = $fromDate->subMonth();
                break;
            case 'year':
                $fromDate = $fromDate->subYear();
                break;
        }
        
        $metrics = HealthMetric::where('patient_id', $patientId)
            ->where('type', $type)
            ->where('measured_at', '>=', $fromDate)
            ->orderBy('measured_at', 'asc')
            ->get();
            
        // Calculate statistics
        $values = $metrics->pluck('value')
            ->map(function ($value) {
                // Handle blood pressure separately
                if (strpos($value, '/') !== false) {
                    return floatval(explode('/', $value)[0]); // Use systolic for calculations
                }
                return floatval($value);
            })
            ->filter(); // Remove non-numeric values
            
        $stats = [
            'count' => $metrics->count(),
            'latest' => $metrics->last(),
            'average' => $values->avg(),
            'min' => $values->min(),
            'max' => $values->max(),
            'trend' => [], // Data points for charting
            'reference_range' => HealthMetric::getReferenceRanges()[$type] ?? null
        ];
        
        // Format data for trend chart (matching React Native component expectations)
        foreach ($metrics as $metric) {
            $stats['trend'][] = [
                'id' => $metric->id,
                'date' => $metric->measured_at->format('Y-m-d'),
                'time' => $metric->measured_at->format('H:i A'),
                'value' => $metric->value,
                'status' => $metric->status,
                'source' => $metric->source,
                'context' => $metric->context,
                'notes' => $metric->notes
            ];
        }
        
        return response()->json($stats);
    }
    
    /**
     * Get metrics grouped by category for dashboard overview
     */
    public function categorizedMetrics(Request $request)
    {
        // ✨ NEW: Get target profile ID from header or use authenticated user
        $targetProfileId = $request->header('X-Active-Profile-ID') ?: auth()->id();

        // ✨ NEW: Validate profile access
        $currentUser = auth()->user();
        if (!$currentUser->canManageProfile($targetProfileId)) {
            return response()->json(['error' => 'Permission denied for this profile'], 403);
        }

        $patientId = $targetProfileId;
        
        $metrics = HealthMetric::where('patient_id', $patientId)
            ->whereNotNull('category')
            ->orderBy('measured_at', 'desc')
            ->get();
            
        // Group by category and subcategory
        $grouped = $metrics->groupBy('category')->map(function ($categoryMetrics, $category) {
            return $categoryMetrics->groupBy('subcategory')->map(function ($subCategoryMetrics, $subcategory) {
                return $subCategoryMetrics->take(5); // Latest 5 of each type
            });
        });
        
        return response()->json([
            'categorized_metrics' => $grouped,
            'reference_ranges' => HealthMetric::getReferenceRanges()
        ]);
    }
    
    /**
     * Get health insights based on latest metrics
     */
    public function insights(Request $request)
    {
        // ✨ NEW: Get target profile ID from header or use authenticated user
        $targetProfileId = $request->header('X-Active-Profile-ID') ?: auth()->id();

        // ✨ NEW: Validate profile access
        $currentUser = auth()->user();
        if (!$currentUser->canManageProfile($targetProfileId)) {
            return response()->json(['error' => 'Permission denied for this profile'], 403);
        }

        $patientId = $targetProfileId;
        
        // Get latest metric of each type
        $latestMetrics = HealthMetric::where('patient_id', $patientId)
            ->whereNotNull('status')
            ->get()
            ->groupBy('type')
            ->map(function ($typeMetrics) {
                return $typeMetrics->sortByDesc('measured_at')->first();
            });
            
        $insights = [];
        
        foreach ($latestMetrics as $metric) {
            if ($metric->status === 'high') {
                $insights[] = [
                    'metric_id' => $metric->type,
                    'title' => $this->getInsightTitle($metric),
                    'description' => $this->getInsightDescription($metric),
                    'severity' => $metric->status === 'high' ? 'attention' : 'warning',
                    'date' => $metric->measured_at->format('Y-m-d')
                ];
            } else if ($metric->status === 'borderline') {
                $insights[] = [
                    'metric_id' => $metric->type,
                    'title' => $this->getInsightTitle($metric),
                    'description' => $this->getInsightDescription($metric),
                    'severity' => 'warning',
                    'date' => $metric->measured_at->format('Y-m-d')
                ];
            }
        }
        
        return response()->json(['insights' => $insights]);
    }
    
    private function getInsightTitle($metric)
    {
        $titles = [
            'vitamin_d' => 'Low Vitamin D Levels',
            'alt' => 'Elevated Liver Enzymes',
            'hdl' => 'HDL Cholesterol Levels',
            'ldl' => 'LDL Cholesterol Levels',
            'blood_sugar' => 'Blood Sugar Levels',
            'blood_pressure' => 'Blood Pressure'
        ];
        
        return $titles[$metric->type] ?? ucfirst(str_replace('_', ' ', $metric->type)) . ' Levels';
    }
    
    private function getInsightDescription($metric)
    {
        // Generate contextual descriptions based on metric type and status
        $descriptions = [
            'vitamin_d' => [
                'high' => 'Your Vitamin D levels are below the recommended range. Consider a supplement after consulting with your doctor.',
                'borderline' => 'Your Vitamin D levels are on the lower side. Consider increasing sun exposure or dietary sources.'
            ],
            'alt' => [
                'high' => 'Your ALT levels are above normal. This could indicate liver stress. Avoid alcohol and consult your doctor.',
                'borderline' => 'Your ALT levels are slightly elevated. Monitor your liver health and consider lifestyle changes.'
            ]
            // Add more as needed
        ];
        
        return $descriptions[$metric->type][$metric->status] ?? 
               "Your {$metric->type} reading of {$metric->value} {$metric->unit} needs attention.";
    }
    
    /**
     * Delete a health metric
     */
    public function destroy($id)
    {
        // ✨ NEW: Get target profile ID from header or use authenticated user
        $targetProfileId = request()->header('X-Active-Profile-ID') ?: auth()->id();

        // ✨ NEW: Validate profile access
        $currentUser = auth()->user();
        if (!$currentUser->canManageProfile($targetProfileId)) {
            return response()->json(['error' => 'Permission denied for this profile'], 403);
        }

        $patientId = $targetProfileId;
        
        $metric = HealthMetric::where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();
            
        $metric->delete();
        
        return response()->json([
            'message' => 'Health metric deleted successfully'
        ]);
    }
}
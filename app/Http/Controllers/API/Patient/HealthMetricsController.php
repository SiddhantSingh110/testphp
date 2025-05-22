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
            'type' => 'required|string', // Remove strict validation to allow any parameter name
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

        $patientId = auth()->id();
        
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
     * Get all metrics for the authenticated patient
     */
    public function index(Request $request)
    {
        $patientId = auth()->id();
        
        $query = HealthMetric::where('patient_id', $patientId);
        
        // Apply filters
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }
        
        if ($request->has('subcategory')) {
            $query->where('subcategory', $request->subcategory);
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('from_date')) {
            $query->where('measured_at', '>=', $request->from_date);
        }
        
        if ($request->has('to_date')) {
            $query->where('measured_at', '<=', $request->to_date);
        }
        
        $metrics = $query->orderBy('measured_at', 'desc')->get();
        
        // Add reference ranges to response
        $metricsWithRanges = $metrics->map(function ($metric) {
            $ranges = HealthMetric::getReferenceRanges();
            $metric->reference_range = $ranges[$metric->type] ?? null;
            return $metric;
        });
        
        return response()->json([
            'metrics' => $metricsWithRanges,
            'reference_ranges' => HealthMetric::getReferenceRanges()
        ]);
    }
    
    /**
     * Get stats and trends for a specific metric type
     */
    public function trends(Request $request, $type)
    {
        $patientId = auth()->id();
        
        // Validate type - now accepts any parameter name
        if (empty($request->type) || strlen($request->type) > 100) {
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
        $patientId = auth()->id();
        
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
        $patientId = auth()->id();
        
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
        $patientId = auth()->id();
        
        $metric = HealthMetric::where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();
            
        $metric->delete();
        
        return response()->json([
            'message' => 'Health metric deleted successfully'
        ]);
    }
}
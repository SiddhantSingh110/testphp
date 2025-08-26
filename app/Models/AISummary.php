<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model;

class AISummary extends Model
{
    use HasFactory;
    
    protected $table = 'ai_summaries';

    protected $fillable = [
        'report_id', 'raw_text', 'summary_json', 'confidence_score', 'ai_model_used'
    ];

    protected $casts = [
        'summary_json' => 'array',
    ];

    public function report()
    {
        return $this->belongsTo(PatientReport::class, 'report_id');
    }
}

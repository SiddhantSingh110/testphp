<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>AI Summary Report</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            color: #333;
            margin: 0;
            padding: 30px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 3px solid #1a66ff;
            padding-bottom: 20px;
        }
        
        .header h1 {
            margin: 0;
            color: #1a66ff;
            font-size: 32px;
            font-weight: bold;
        }
        
        .header p {
            margin-top: 10px;
            color: #666;
            font-size: 16px;
        }
        
        .report-id {
            position: absolute;
            top: 30px;
            right: 30px;
            font-size: 12px;
            color: #888;
        }
        
        .patient-info {
            background: linear-gradient(to right, #f8f9fa, #ffffff);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            border-left: 5px solid #1a66ff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .patient-info h2 {
            margin: 0 0 15px 0;
            color: #1a66ff;
            font-size: 20px;
        }
        
        .info-grid {
            display: table;
            width: 100%;
        }
        
        .info-item {
            display: table-row;
        }
        
        .info-label {
            display: table-cell;
            font-weight: bold;
            padding: 5px 15px 5px 0;
            width: 120px;
            color: #555;
        }
        
        .info-value {
            display: table-cell;
            padding: 5px 0;
        }
        
        .diagnosis-section {
            background-color: #fff;
            border: 2px solid #1a66ff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .diagnosis-section h2 {
            margin: 0 0 10px 0;
            color: #1a66ff;
            font-size: 18px;
        }
        
        .diagnosis-text {
            font-size: 22px;
            font-weight: bold;
            color: #333;
            margin: 0;
        }
        
        .findings-section {
            margin-bottom: 30px;
        }
        
        .findings-section h2 {
            color: #1a66ff;
            font-size: 20px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        
        .finding-item {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid;
            background-color: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }
        
        .status-normal {
            border-left-color: #28a745;
            background-color: rgba(40, 167, 69, 0.05);
        }
        
        .status-borderline {
            border-left-color: #ffc107;
            background-color: rgba(255, 193, 7, 0.05);
        }
        
        .status-high {
            border-left-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.05);
        }
        
        .finding-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .normal-indicator {
            background-color: #28a745;
        }
        
        .borderline-indicator {
            background-color: #ffc107;
        }
        
        .high-indicator {
            background-color: #dc3545;
        }
        
        .finding-title {
            font-weight: bold;
            font-size: 16px;
        }
        
        .finding-content {
            margin-left: 22px;
        }
        
        .finding-value {
            font-size: 15px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .finding-reference {
            color: #666;
            font-size: 13px;
            border-top: 1px dashed #ddd;
            padding-top: 5px;
            margin-top: 5px;
        }
        
        .finding-description {
            font-size: 13px;
            color: #555;
            margin-top: 10px;
            font-style: italic;
        }
        
        .recommendations-section {
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .recommendations-section h2 {
            color: #1a66ff;
            font-size: 20px;
            margin-bottom: 15px;
        }
        
        .recommendations-list {
            margin: 0;
            padding-left: 20px;
        }
        
        .recommendations-list li {
            margin-bottom: 12px;
            position: relative;
            padding-left: 15px;
        }
        
        .recommendations-list li::before {
            content: '•';
            color: #1a66ff;
            font-weight: bold;
            position: absolute;
            left: 0;
        }
        
        .confidence-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .confidence-badge {
            display: inline-block;
            padding: 10px 20px;
            background-color: #1a66ff;
            color: white;
            border-radius: 25px;
            font-weight: bold;
            font-size: 18px;
        }
        
        .confidence-text {
            color: #666;
            font-size: 13px;
            margin-top: 10px;
        }
        
        .app-promotion {
            background: linear-gradient(135deg, #1a66ff, #0047cc);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-top: 40px;
            box-shadow: 0 4px 12px rgba(26, 102, 255, 0.3);
        }
        
        .app-promotion h3 {
            margin: 0 0 10px 0;
            font-size: 20px;
        }
        
        .app-promotion p {
            margin: 0 0 15px 0;
            font-size: 15px;
        }
        
        .app-url {
            display: inline-block;
            background-color: white;
            color: #1a66ff;
            padding: 10px 20px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 12px;
        }
        
        .disclaimer {
            font-style: italic;
            color: #888;
            margin-top: 10px;
        }
        
        @media print {
            .app-promotion {
                background: #1a66ff !important;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="report-id">ID: #{{ $report->id }} | {{ $report->created_at->format('Y-m-d H:i') }}</div>
    
    <div class="header">
        <h1>Medical Report Analysis</h1>
        <p>Powered by Webshark Health AI</p>
    </div>

    <!-- Patient Information -->
    <div class="patient-info">
        <h2>Patient Details</h2>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Name:</div>
                <div class="info-value">{{ $summary['patient_name'] ?? 'N/A' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Age:</div>
                <div class="info-value">{{ $summary['patient_age'] ?? 'N/A' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Gender:</div>
                <div class="info-value">{{ $summary['patient_gender'] ?? 'N/A' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Report Date:</div>
                <div class="info-value">{{ $report->report_date }}</div>
            </div>
        </div>
    </div>

    <!-- Diagnosis -->
    <div class="diagnosis-section">
        <h2>DIAGNOSIS</h2>
        <p class="diagnosis-text">{{ $summary['diagnosis'] ?? 'N/A' }}</p>
    </div>

    <!-- Key Findings -->
    @if(!empty($summary['key_findings']))
    <div class="findings-section">
        <h2>Key Medical Findings</h2>
        
        @foreach($summary['key_findings'] as $finding)
            @if(is_array($finding))
                <div class="finding-item status-{{ $finding['status'] ?? 'normal' }}">
                    <div class="finding-header">
                        <span class="status-indicator {{ $finding['status'] ?? 'normal' }}-indicator"></span>
                        <span class="finding-title">{{ $finding['finding'] ?? '' }}</span>
                    </div>
                    <div class="finding-content">
                        <div class="finding-value">
                            <strong>Value:</strong> {{ $finding['value'] ?? 'N/A' }}
                        </div>
                        @if(isset($finding['reference']) && $finding['reference'])
                            <div class="finding-reference">
                                <strong>Reference Range:</strong> {{ $finding['reference'] }}
                            </div>
                        @endif
                        @if(isset($finding['description']) && $finding['description'])
                            <div class="finding-description">
                                {{ $finding['description'] }}
                            </div>
                        @endif
                    </div>
                </div>
            @else
                <div class="finding-item status-normal">
                    <div class="finding-header">
                        <span class="status-indicator normal-indicator"></span>
                        <span class="finding-title">Finding</span>
                    </div>
                    <div class="finding-content">
                        <div class="finding-value">{{ $finding }}</div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
    @endif

    <!-- Recommendations -->
    @if(!empty($summary['recommendations']))
    <div class="recommendations-section">
        <h2>Medical Recommendations</h2>
        <ul class="recommendations-list">
            @foreach($summary['recommendations'] as $rec)
                <li>{{ $rec }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Confidence Score -->
    <div class="confidence-section">
        <span class="confidence-badge">AI Confidence: {{ $confidence ?? 'N/A' }}%</span>
        <p class="confidence-text">This analysis is powered by advanced medical AI with high accuracy</p>
    </div>

    <!-- App Promotion -->
    <div class="app-promotion">
        <h3>Get Real-time Health Insights on Your Phone</h3>
        <p>Track your health metrics, schedule appointments, and get instant AI-powered analysis with the Webshark Health App</p>
        <a href="https://apps.apple.com/in/health.webshark" class="app-url">Download Now on App Store</a>
    </div>

    <!-- Footer -->
    <div class="footer">
        © {{ date('Y') }} Webshark Health. All rights reserved.
        <div class="disclaimer">
            This AI-generated report should be reviewed by a healthcare professional for medical advice.
        </div>
    </div>
</body>
</html>
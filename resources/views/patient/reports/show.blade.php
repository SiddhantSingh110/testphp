<!DOCTYPE html>
<html>
<head>
    <title>Report Details</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 20px;
            background-color: #f8f9fa;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .card {
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            background-color: #fff;
        }
        .patient-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        .finding-item {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .finding-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .finding-title {
            font-weight: bold;
            font-size: 1.1em;
            margin-bottom: 5px;
        }
        .finding-reference {
            color: #666;
            margin-top: 4px;
            font-size: 0.9em;
        }
        .status-normal {
            background-color: rgba(40, 167, 69, 0.1);
            border-left-color: #28a745;
        }
        .status-borderline {
            background-color: rgba(255, 193, 7, 0.1);
            border-left-color: #ffc107;
        }
        .status-high {
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: #dc3545;
        }
        .status-indicator {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            margin-right: 8px;
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
        .recommendations-list li {
            margin-bottom: 10px;
            padding-left: 10px;
            border-left: 3px solid #007bff;
        }
        .hindi-section {
            background-color: #fff8e1;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border-left: 4px solid #ff9800;
        }
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .report-title {
            margin: 0;
        }
        .view-file-btn {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
        }
        .view-file-btn:hover {
            background-color: #0069d9;
        }
        .confidence-score {
            display: inline-block;
            padding: 5px 10px;
            background-color: #e9ecef;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
        }
        .color-code-legend {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            margin-right: 15px;
        }
        
        /* Accordion and Tab Styles */
        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            margin-top: 10px;
        }
        .accordion-active {
            max-height: 2000px;
        }
        .accordion-toggle {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .accordion-toggle .toggle-icon {
            font-size: 18px;
            transition: transform 0.3s ease;
        }
        .accordion-active-icon {
            transform: rotate(180deg);
        }
        .tabs-container {
            margin-top: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            overflow: hidden;
        }
        .tabs-header {
            display: flex;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        .tab-button {
            padding: 10px 15px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s ease;
            flex: 1;
            text-align: center;
        }
        .tab-button:hover {
            background-color: #e9ecef;
        }
        .tab-button.active {
            color: #007bff;
            border-bottom: 2px solid #007bff;
            background-color: #e7f1ff;
        }
        .tab-content {
            display: none;
            padding: 15px;
        }
        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .loading-spinner {
            display: flex;
            justify-content: center;
            padding: 20px;
        }
        .loading-spinner::after {
            content: "";
            width: 30px;
            height: 30px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .finding-detail-item {
            display: flex;
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 8px;
            background-color: #f8f9fa;
            align-items: flex-start;
        }
        .finding-detail-icon {
            font-size: 24px;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .finding-detail-content {
            flex-grow: 1;
        }
        .finding-detail-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        .finding-detail-description {
            color: #666;
        }
        .finding-chevron {
            margin-left: 10px;
            transition: transform 0.3s;
        }
        .rotate-chevron {
            transform: rotate(180deg);
        }
    </style>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <h1>Report Details</h1>
        <div id="reportDetails">Loading...</div>
    </div>

    <script>
        const reportId = window.location.pathname.split('/').pop(); // get {id} from URL
        const token = localStorage.getItem('patientToken');
        let reportData = null;

        // Fetch the report data
        fetch(`/api/patient/reports/${reportId}`, {
            headers: {
                'Authorization': 'Bearer ' + token,
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            reportData = data;
            const aiSummary = reportData.ai_summary || {};
            
            // Generate the findings with accordion
            let keyFindings = '<p>None available</p>';
            if (aiSummary.key_findings && aiSummary.key_findings.length > 0) {
                keyFindings = aiSummary.key_findings.map((finding, index) => {
                    // Handle both string format (backward compatibility) and object format (new)
                    if (typeof finding === 'string') {
                        // Determine status based on text keywords
                        let status = 'normal';
                        let text = finding.toLowerCase();
                        
                        if (text.includes('high') || text.includes('extremely') || 
                            text.includes('significantly') || text.includes('elevated') || 
                            text.includes('increased') || text.includes('excessive')) {
                            
                            if (text.includes('slightly') || text.includes('mild') || 
                                text.includes('borderline')) {
                                status = 'borderline';
                            } else {
                                status = 'high';
                            }
                        } 
                        else if (text.includes('low') || text.includes('decreased') || 
                                text.includes('deficiency') || text.includes('deficit') ||
                                text.includes('lower than normal')) {
                            
                            if (text.includes('slightly') || text.includes('mild') || 
                                text.includes('borderline')) {
                                status = 'borderline';
                            } else {
                                status = 'high';
                            }
                        }
                        
                        // Only normal findings don't get accordions
                        if (status === 'normal') {
                            return `<div class="finding-item status-${status}">
                                <span class="status-indicator ${status}-indicator"></span>${finding}
                            </div>`;
                        } else {
                            return createAccordionItem({
                                finding: finding,
                                value: '',
                                reference: '',
                                status: status,
                                description: finding
                            }, index);
                        }
                    } else {
                        // New format with detailed structure
                        const status = finding.status || 'normal';
                        
                        // Extract or construct description
                        let description = finding.description || '';
                        if (!description && finding.finding) {
                            // Create description from other fields
                            if (finding.status === 'high') {
                                if (finding.finding.toLowerCase().includes('low') || 
                                    finding.finding.toLowerCase().includes('deficiency')) {
                                    description = `Low ${finding.finding}`;
                                } else {
                                    description = `Elevated ${finding.finding}`;
                                }
                            } else if (finding.status === 'borderline') {
                                description = `Slightly abnormal ${finding.finding}`;
                            } else {
                                description = `Normal ${finding.finding}`;
                            }
                        }
                        
                        // Only add accordion for abnormal findings
                        if (status === 'normal') {
                            return `<div class="finding-item status-${status}">
                                <span class="status-indicator ${status}-indicator"></span>
                                <div class="finding-title">${description || finding.finding}</div>
                                <div><strong>${finding.finding}:</strong> ${finding.value}</div>
                                <div class="finding-reference">Reference: ${finding.reference || 'Not provided'}</div>
                            </div>`;
                        } else {
                            return createAccordionItem(finding, index);
                        }
                    }
                }).join('');
            }
            
            // Generate recommendations list
            let recommendations = '<p>None available</p>';
            if (aiSummary.recommendations && aiSummary.recommendations.length > 0) {
                recommendations = '<ul class="recommendations-list">' + 
                    aiSummary.recommendations.map(rec => `<li>${rec}</li>`).join('') +
                    '</ul>';
            }
            
            document.getElementById('reportDetails').innerHTML = `
                <div class="card">
                    <div class="report-header">
                        <h2 class="report-title">${reportData.title}</h2>
                        <a href="${reportData.file_url}" target="_blank" class="view-file-btn">View Original Report</a>
                    </div>
                    
                    <p><strong>Report Date:</strong> ${reportData.report_date}</p>
                    <p><strong>Uploaded At:</strong> ${reportData.uploaded_at}</p>
                    <p><strong>Uploaded By:</strong> ${reportData.uploaded_by}</p>
                    
                    <div class="patient-info">
                        <h3>Patient Information</h3>
                        <p><strong>Name:</strong> ${aiSummary.patient_name || 'N/A'}</p>
                        <p><strong>Age:</strong> ${aiSummary.patient_age || 'N/A'}</p>
                        <p><strong>Gender:</strong> ${aiSummary.patient_gender || 'N/A'}</p>
                    </div>
                    
                    <h3>Diagnosis</h3>
                    <p>${aiSummary.diagnosis || 'N/A'}</p>
                    
                    <div class="color-code-legend">
                        <div class="legend-item">
                            <span class="status-indicator normal-indicator"></span> Normal
                        </div>
                        <div class="legend-item">
                            <span class="status-indicator borderline-indicator"></span> Borderline
                        </div>
                        <div class="legend-item">
                            <span class="status-indicator high-indicator"></span> High
                        </div>
                    </div>
                    
                    <h3>Key Findings</h3>
                    <p><small><i>Click on abnormal findings to see more details</i></small></p>
                    ${keyFindings}
                    
                    <h3>Recommendations</h3>
                    ${recommendations}
                    
                    <p><strong>Confidence Score:</strong> <span class="confidence-score">${aiSummary.confidence_score || 'N/A'}</span></p>
                    
                    <div class="hindi-section">
                        <h3>Hindi Summary</h3>
                        <div>${aiSummary.hindi_version || 'N/A'}</div>
                    </div>
                </div>
            `;
            
            // Initialize accordions after the content is loaded
            initAccordions();
        })
        .catch(error => {
            document.getElementById('reportDetails').innerHTML = '<div class="card">Failed to load report. Please try again later.</div>';
            console.error(error);
        });
        
        // Function to create accordion item
        function createAccordionItem(finding, index) {
            const statusClass = `status-${finding.status}`;
            const indicatorClass = `${finding.status}-indicator`;
            
            return `
                <div class="finding-item ${statusClass} accordion" data-index="${index}">
                    <div class="accordion-toggle">
                        <div>
                            <span class="status-indicator ${indicatorClass}"></span>
                            <div class="finding-title">${finding.description || finding.finding}</div>
                            <div><strong>${finding.finding}:</strong> ${finding.value}</div>
                            <div class="finding-reference">Reference: ${finding.reference || 'Not provided'}</div>
                        </div>
                        <i class="fas fa-chevron-down finding-chevron"></i>
                    </div>
                    
                    <div class="accordion-content" data-finding='${JSON.stringify(finding)}'>
                        <div class="loading-spinner"></div>
                    </div>
                </div>
            `;
        }
        
        // Initialize accordions
        function initAccordions() {
            const accordions = document.querySelectorAll('.accordion');
            
            accordions.forEach(accordion => {
                accordion.addEventListener('click', function() {
                    const content = this.querySelector('.accordion-content');
                    const chevron = this.querySelector('.finding-chevron');
                    
                    // Toggle accordion
                    if (!content.classList.contains('accordion-active')) {
                        // Load content if it's not already loaded
                        if (content.innerHTML.includes('loading-spinner')) {
                            const findingData = JSON.parse(content.getAttribute('data-finding'));
                            loadFindingDetails(findingData, content);
                        }
                        
                        // Open accordion
                        content.classList.add('accordion-active');
                        chevron.classList.add('rotate-chevron');
                    } else {
                        // Close accordion
                        content.classList.remove('accordion-active');
                        chevron.classList.remove('rotate-chevron');
                    }
                });
            });
        }
        
            // Load finding details from API
            function loadFindingDetails(finding, contentElement) {
                // Log the finding data for debugging
                console.log('Finding data:', finding);
                
                fetch(`/api/patient/reports/${reportId}/findings`, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ 
                        // Important: The API expects the finding object directly, not nested
                        finding: finding 
                    })
                })
                .then(res => {
                    if (!res.ok) {
                        console.error('API error:', res.status, res.statusText);
                        throw new Error(`API error: ${res.status} ${res.statusText}`);
                    }
                    return res.json();
                })
                .then(data => {
                    console.log('API response:', data);
                    
                    if (data.success && data.details) {
                        // Create tabs UI with the detailed information
                        const tabsHTML = createFindingDetailsTabs(data.details);
                        contentElement.innerHTML = tabsHTML;
                        
                        // Initialize tabs
                        initDetailTabs(contentElement);
                    } else {
                        // Handle error case
                        contentElement.innerHTML = `
                            <div style="padding: 15px;">
                                <p>Could not load additional details for this finding.</p>
                                <button onclick="retryLoadDetails(this)" class="retry-button" style="padding: 8px 15px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; margin-top: 10px;">Try Again</button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error("Error loading finding details:", error);
                    contentElement.innerHTML = `
                        <div style="padding: 15px;">
                            <p>An error occurred while loading details: ${error.message}</p>
                            <button onclick="retryLoadDetails(this)" class="retry-button" style="padding: 8px 15px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; margin-top: 10px;">Try Again</button>
                        </div>
                    `;
                });
            }

            // Function to retry loading details
            function retryLoadDetails(button) {
                const contentElement = button.closest('.accordion-content');
                const findingData = JSON.parse(contentElement.getAttribute('data-finding'));
                
                // Reset content to loading spinner
                contentElement.innerHTML = '<div class="loading-spinner"></div>';
                
                // Try loading again
                loadFindingDetails(findingData, contentElement);
                
                // Prevent event propagation
                event.stopPropagation();
            }
        
        // Create tabs UI for finding details
        function createFindingDetailsTabs(details) {
            const tabsHTML = `
                <div class="tabs-container">
                    <div class="tabs-header">
                        <button class="tab-button active" data-tab="cases">
                            <i class="fas fa-file-medical"></i> Cases
                        </button>
                        <button class="tab-button" data-tab="symptoms">
                            <i class="fas fa-notes-medical"></i> Symptoms
                        </button>
                        <button class="tab-button" data-tab="remedies">
                            <i class="fas fa-pills"></i> Remedies
                        </button>
                        <button class="tab-button" data-tab="consequences">
                            <i class="fas fa-exclamation-triangle"></i> Consequences
                        </button>
                        <button class="tab-button" data-tab="next-steps">
                            <i class="fas fa-clipboard-list"></i> Next Steps
                        </button>
                    </div>
                    
                    <div class="tab-content active" id="cases-tab">
                        ${createDetailItemsList(details.cases)}
                    </div>
                    
                    <div class="tab-content" id="symptoms-tab">
                        ${createDetailItemsList(details.symptoms)}
                    </div>
                    
                    <div class="tab-content" id="remedies-tab">
                        ${createDetailItemsList(details.remedies)}
                    </div>
                    
                    <div class="tab-content" id="consequences-tab">
                        ${createDetailItemsList(details.consequences)}
                    </div>
                    
                    <div class="tab-content" id="next-steps-tab">
                        ${createDetailItemsList(details.next_steps)}
                    </div>
                </div>
            `;
            
            return tabsHTML;
        }
        
        // Create a list of detail items with icons
        function createDetailItemsList(items) {
            if (!items || items.length === 0) {
                return '<p>No information available.</p>';
            }
            
            // Sort by priority if available
            const sortedItems = [...items].sort((a, b) => {
                return (a.priority || 999) - (b.priority || 999);
            });
            
            return sortedItems.map(item => {
                const icon = item.icon || '💡';
                
                return `
                    <div class="finding-detail-item">
                        <div class="finding-detail-icon">${icon}</div>
                        <div class="finding-detail-content">
                            <div class="finding-detail-title">${item.title}</div>
                            <div class="finding-detail-description">${item.description}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        // Initialize tabs functionality
        function initDetailTabs(parentElement) {
            const tabButtons = parentElement.querySelectorAll('.tab-button');
            const tabContents = parentElement.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', event => {
                    // Prevent the click from triggering the accordion
                    event.stopPropagation();
                    
                    // Get the tab to show
                    const tabName = button.getAttribute('data-tab');
                    
                    // Hide all tabs
                    tabContents.forEach(tab => tab.classList.remove('active'));
                    
                    // Show the selected tab
                    parentElement.querySelector(`#${tabName}-tab`).classList.add('active');
                    
                    // Update active button
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    button.classList.add('active');
                });
            });
        }
    </script>
</body>
</html>
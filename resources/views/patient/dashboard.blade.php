<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard - Webshark Medical</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.2/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .status-normal {
            background-color: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
        }
        .status-borderline {
            background-color: rgba(255, 193, 7, 0.1);
            border-left: 4px solid #ffc107;
        }
        .status-high {
            background-color: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .normal-indicator { background-color: #28a745; }
        .borderline-indicator { background-color: #ffc107; }
        .high-indicator { background-color: #dc3545; }
        .dashboard-stat {
            transition: all 0.3s ease;
        }
        .dashboard-stat:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .nav-item {
            transition: all 0.2s ease;
        }
        .nav-item:hover {
            transform: translateX(5px);
        }
        .report-card {
            transition: all 0.3s ease;
        }
        .report-card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    @if(session('success'))
        <div class="fixed top-4 right-4 bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded shadow-md z-50 flex items-center" role="alert">
            <i class="fas fa-check-circle mr-2"></i>
            <span>{{ session('success') }}</span>
            <button class="ml-4 text-green-700" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    @endif

    <div class="min-h-screen flex flex-col">
        <!-- Header -->
        <header class="bg-white shadow-sm sticky top-0 z-40">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <div class="flex items-center">
                    <div class="text-blue-600 mr-3">
                        <i class="fas fa-heartbeat text-2xl"></i>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900">Webshark Medical</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="flex items-center bg-blue-50 rounded-full px-4 py-2">
                        <i class="fas fa-user-circle text-blue-600 mr-2"></i>
                        <span class="text-gray-700 font-medium">{{ auth()->guard('patient')->user()->name }}</span>
                    </div>
                    <form method="POST" action="{{ route('patient.logout') }}">
                        @csrf
                        <button type="submit" class="bg-white hover:bg-red-50 text-red-600 px-4 py-2 rounded-md border border-red-200 hover:border-red-300 transition-colors flex items-center">
                            <i class="fas fa-sign-out-alt mr-2"></i>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-grow">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="lg:flex lg:gap-8">
                    <!-- Sidebar / Menu -->
                    <div class="lg:w-1/4 mb-8 lg:mb-0">
                        <div class="bg-white shadow-sm rounded-xl overflow-hidden sticky top-24">
                            <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
                                <div class="flex items-center">
                                    <div class="bg-white rounded-full h-12 w-12 flex items-center justify-center mr-4">
                                        <i class="fas fa-user text-blue-500 text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-white font-medium">{{ auth()->guard('patient')->user()->name }}</p>
                                        <p class="text-blue-100 text-sm">Patient</p>
                                    </div>
                                </div>
                            </div>
                            <nav class="py-2">
                                <ul>
                                    <li class="nav-item">
                                        <a href="{{ route('patient.dashboard') }}" class="flex items-center px-6 py-3 bg-blue-50 text-blue-700 font-medium">
                                            <i class="fas fa-home w-6 mr-3"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="{{ route('patient.upload') }}" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-50">
                                            <i class="fas fa-upload w-6 mr-3"></i>
                                            <span>Upload Report</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="{{ route('patient.reports.all') }}" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-50">
                                            <i class="fas fa-file-medical w-6 mr-3"></i>
                                            <span>My Medical Records</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-50">
                                            <i class="fas fa-calendar-alt w-6 mr-3"></i>
                                            <span>Appointments</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-50">
                                            <i class="fas fa-cog w-6 mr-3"></i>
                                            <span>Profile Settings</span>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
                                <div class="flex items-center text-gray-600">
                                    <i class="fas fa-headset mr-3"></i>
                                    <span>24/7 Support</span>
                                </div>
                                <p class="text-gray-500 text-sm mt-1">Need help? Contact our support team</p>
                            </div>
                        </div>
                    </div>

                    <!-- Main Dashboard Content -->
                    <div class="lg:w-3/4">
                        <!-- Welcome Card -->
                        <div class="bg-white shadow-sm rounded-xl p-6 mb-8">
                            <div class="flex items-center justify-between mb-6">
                                <h2 class="text-2xl font-bold text-gray-800">Welcome back, {{ auth()->guard('patient')->user()->name }}</h2>
                                <div class="text-gray-500 text-sm">
                                    <i class="fas fa-calendar-day mr-1"></i>
                                    <span id="currentDate">{{ date('l, F j, Y') }}</span>
                                </div>
                            </div>
                            
                            <p class="text-gray-600 mb-8">Your health is our priority. Track your medical records, upload reports, and schedule appointments all in one place.</p>
                            
                            <!-- Quick Stats -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                                <div class="bg-blue-50 rounded-lg p-4 dashboard-stat flex items-center">
                                    <div class="rounded-full bg-blue-500 p-3 mr-4 text-white">
                                        <i class="fas fa-file-medical"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-2xl font-bold text-blue-700">0</h3>
                                        <p class="text-blue-600">Total Reports</p>
                                    </div>
                                </div>
                                
                                <div class="bg-green-50 rounded-lg p-4 dashboard-stat flex items-center">
                                    <div class="rounded-full bg-green-500 p-3 mr-4 text-white">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-2xl font-bold text-green-700">0</h3>
                                        <p class="text-green-600">Appointments</p>
                                    </div>
                                </div>
                                
                                <div class="bg-purple-50 rounded-lg p-4 dashboard-stat flex items-center">
                                    <div class="rounded-full bg-purple-500 p-3 mr-4 text-white">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-2xl font-bold text-purple-700">0</h3>
                                        <p class="text-purple-600">Notifications</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Actions -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                <a href="{{ route('patient.upload') }}" class="bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg p-4 shadow-sm hover:shadow-md transition-all flex flex-col items-center justify-center text-center h-32">
                                    <i class="fas fa-file-upload text-2xl mb-2"></i>
                                    <h3 class="font-medium">Upload Report</h3>
                                    <p class="text-xs text-blue-100 mt-1">Add a new medical report</p>
                                </a>
                                
                                <a href="#" class="bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg p-4 shadow-sm hover:shadow-md transition-all flex flex-col items-center justify-center text-center h-32">
                                    <i class="fas fa-calendar-plus text-2xl mb-2"></i>
                                    <h3 class="font-medium">Book Appointment</h3>
                                    <p class="text-xs text-green-100 mt-1">Schedule with a doctor</p>
                                </a>
                                
                                <a href="#" class="bg-gradient-to-r from-purple-500 to-purple-600 text-white rounded-lg p-4 shadow-sm hover:shadow-md transition-all flex flex-col items-center justify-center text-center h-32">
                                    <i class="fas fa-notes-medical text-2xl mb-2"></i>
                                    <h3 class="font-medium">Health Metrics</h3>
                                    <p class="text-xs text-purple-100 mt-1">Track your vital statistics</p>
                                </a>
                                
                                <a href="#" class="bg-gradient-to-r from-gray-600 to-gray-700 text-white rounded-lg p-4 shadow-sm hover:shadow-md transition-all flex flex-col items-center justify-center text-center h-32">
                                    <i class="fas fa-user-md text-2xl mb-2"></i>
                                    <h3 class="font-medium">Find Doctor</h3>
                                    <p class="text-xs text-gray-300 mt-1">Connect with specialists</p>
                                </a>
                            </div>
                        </div>

                        <!-- Health Status Overview -->
                        <div class="bg-white shadow-sm rounded-xl p-6 mb-8">
                            <div class="flex justify-between items-center mb-6">
                                <h2 class="text-xl font-bold text-gray-800">
                                    <i class="fas fa-heartbeat text-red-500 mr-2"></i>
                                    Health Status Overview
                                </h2>
                                <div class="flex space-x-2">
                                    <span class="flex items-center text-sm">
                                        <span class="status-indicator normal-indicator"></span> Normal
                                    </span>
                                    <span class="flex items-center text-sm">
                                        <span class="status-indicator borderline-indicator"></span> Borderline
                                    </span>
                                    <span class="flex items-center text-sm">
                                        <span class="status-indicator high-indicator"></span> Attention
                                    </span>
                                </div>
                            </div>
                            
                            <div id="healthStatusOverview">
                                <!-- Placeholder for when there's no data -->
                                <div class="text-center py-10 bg-gray-50 rounded-lg">
                                    <i class="fas fa-file-medical-alt text-gray-300 text-4xl mb-3"></i>
                                    <p class="text-gray-500">No health data available yet.</p>
                                    <p class="text-gray-400 text-sm mt-1">Upload your medical reports to see your health status.</p>
                                    <a href="{{ route('patient.upload') }}" class="mt-4 inline-block bg-blue-100 text-blue-700 py-2 px-4 rounded-md">
                                        <i class="fas fa-upload mr-1"></i> Upload Your First Report
                                    </a>
                                </div>
                                
                                <!-- Example health parameters (hidden by default) -->
                                <div class="hidden">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="status-high p-4 rounded-lg">
                                            <div class="flex items-center mb-2">
                                                <span class="status-indicator high-indicator"></span>
                                                <span class="font-medium">Total Cholesterol</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-xl font-bold">299.00 mg/dL</span>
                                                <span class="text-sm text-gray-500">Ref: 0-200 mg/dL</span>
                                            </div>
                                        </div>
                                        
                                        <div class="status-high p-4 rounded-lg">
                                            <div class="flex items-center mb-2">
                                                <span class="status-indicator high-indicator"></span>
                                                <span class="font-medium">Vitamin D</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-xl font-bold">10.50 ng/mL</span>
                                                <span class="text-sm text-gray-500">Ref: >=30 ng/mL</span>
                                            </div>
                                        </div>
                                        
                                        <div class="status-borderline p-4 rounded-lg">
                                            <div class="flex items-center mb-2">
                                                <span class="status-indicator borderline-indicator"></span>
                                                <span class="font-medium">LDL/HDL Ratio</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-xl font-bold">3.14</span>
                                                <span class="text-sm text-gray-500">Ref: 0.5-3.0 (Low Risk)</span>
                                            </div>
                                        </div>
                                        
                                        <div class="status-normal p-4 rounded-lg">
                                            <div class="flex items-center mb-2">
                                                <span class="status-indicator normal-indicator"></span>
                                                <span class="font-medium">Creatinine</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-xl font-bold">1.02 mg/dL</span>
                                                <span class="text-sm text-gray-500">Ref: 0.7-1.3 mg/dL</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Reports -->
                        <div class="bg-white shadow-sm rounded-xl p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h2 class="text-xl font-bold text-gray-800">
                                    <i class="fas fa-file-medical text-blue-500 mr-2"></i>
                                    Recent Reports
                                </h2>
                                <a href="{{ route('patient.reports.all') }}" class="text-blue-600 hover:text-blue-800 flex items-center">
                                    <span>View All</span>
                                    <i class="fas fa-chevron-right ml-1 text-sm"></i>
                                </a>
                            </div>
                            
                            <div id="recentReports">
                                <!-- Placeholder when there are no reports -->
                                <div class="text-center py-10 bg-gray-50 rounded-lg">
                                    <i class="fas fa-folder-open text-gray-300 text-4xl mb-3"></i>
                                    <p class="text-gray-500">You don't have any reports yet.</p>
                                    <a href="{{ route('patient.upload') }}" class="mt-4 inline-block bg-blue-100 text-blue-700 py-2 px-4 rounded-md">
                                        <i class="fas fa-upload mr-1"></i> Upload Your First Report
                                    </a>
                                </div>

                                <!-- Example report items (hidden by default) -->
                                <div class="hidden">
                                    <div class="report-card border border-gray-100 rounded-lg p-4 mb-4 hover:border-blue-200">
                                        <div class="flex justify-between items-start">
                                            <div class="flex items-start">
                                                <div class="bg-blue-100 p-3 rounded-lg mr-4">
                                                    <i class="fas fa-vial text-blue-600"></i>
                                                </div>
                                                <div>
                                                    <h3 class="font-medium text-gray-800">Complete Blood Count</h3>
                                                    <p class="text-sm text-gray-500">Uploaded on Apr 15, 2023</p>
                                                </div>
                                            </div>
                                            <span class="flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <span class="status-indicator high-indicator"></span> Attention Needed
                                            </span>
                                        </div>
                                        <div class="mt-4 pl-16">
                                            <p class="text-sm text-gray-600">
                                                <strong>Diagnosis:</strong> High Cholesterol, Vitamin D deficiency
                                            </p>
                                            <div class="mt-3 flex space-x-3">
                                                <a href="#" class="text-sm bg-blue-50 hover:bg-blue-100 text-blue-600 px-3 py-1 rounded flex items-center">
                                                    <i class="fas fa-eye mr-1"></i> View
                                                </a>
                                                <a href="#" class="text-sm bg-gray-50 hover:bg-gray-100 text-gray-600 px-3 py-1 rounded flex items-center">
                                                    <i class="fas fa-download mr-1"></i> Download
                                                </a>
                                                <a href="#" class="text-sm bg-green-50 hover:bg-green-100 text-green-600 px-3 py-1 rounded flex items-center">
                                                    <i class="fas fa-share-alt mr-1"></i> Share
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="report-card border border-gray-100 rounded-lg p-4 hover:border-blue-200">
                                        <div class="flex justify-between items-start">
                                            <div class="flex items-start">
                                                <div class="bg-green-100 p-3 rounded-lg mr-4">
                                                    <i class="fas fa-lungs text-green-600"></i>
                                                </div>
                                                <div>
                                                    <h3 class="font-medium text-gray-800">Chest X-Ray</h3>
                                                    <p class="text-sm text-gray-500">Uploaded on Mar 22, 2023</p>
                                                </div>
                                            </div>
                                            <span class="flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <span class="status-indicator normal-indicator"></span> Normal
                                            </span>
                                        </div>
                                        <div class="mt-4 pl-16">
                                            <p class="text-sm text-gray-600">
                                                <strong>Diagnosis:</strong> No significant findings
                                            </p>
                                            <div class="mt-3 flex space-x-3">
                                                <a href="#" class="text-sm bg-blue-50 hover:bg-blue-100 text-blue-600 px-3 py-1 rounded flex items-center">
                                                    <i class="fas fa-eye mr-1"></i> View
                                                </a>
                                                <a href="#" class="text-sm bg-gray-50 hover:bg-gray-100 text-gray-600 px-3 py-1 rounded flex items-center">
                                                    <i class="fas fa-download mr-1"></i> Download
                                                </a>
                                                <a href="#" class="text-sm bg-green-50 hover:bg-green-100 text-green-600 px-3 py-1 rounded flex items-center">
                                                    <i class="fas fa-share-alt mr-1"></i> Share
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 py-6 mt-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                    <div class="flex items-center mb-4 md:mb-0">
                        <div class="text-blue-600 mr-2">
                            <i class="fas fa-heartbeat text-xl"></i>
                        </div>
                        <p class="text-gray-700 font-medium">Webshark Medical</p>
                    </div>
                    
                    <div class="flex space-x-6">
                        <a href="#" class="text-gray-500 hover:text-gray-700">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="text-gray-500 hover:text-gray-700">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="text-gray-500 hover:text-gray-700">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="text-gray-500 hover:text-gray-700">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                </div>
                
                <div class="mt-4 border-t border-gray-100 pt-4 flex flex-col md:flex-row md:justify-between">
                    <p class="text-gray-500 text-sm mb-2 md:mb-0">
                        &copy; 2023 Webshark Medical. All rights reserved.
                    </p>
                    <div class="flex space-x-4 text-sm text-gray-500">
                        <a href="#" class="hover:text-gray-700">Privacy Policy</a>
                        <a href="#" class="hover:text-gray-700">Terms of Service</a>
                        <a href="#" class="hover:text-gray-700">Contact Us</a>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <script>
        // Fetch recent reports
        function fetchRecentReports() {
            // This would be replaced with actual API call
            const token = localStorage.getItem('patient_token');
            
            // Simulating an empty response for now
            const reportsContainer = document.getElementById('recentReports');
            
            // If you have actual data, you can uncomment and modify this
            /*
            fetch('/api/patient/reports/recent', {
                headers: {
                    'Accept': 'application/json',
                    'Authorization': 'Bearer ' + token
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.reports && data.reports.length > 0) {
                    reportsContainer.innerHTML = '';
                    data.reports.forEach(report => {
                        // Create and append report elements
                    });
                }
            })
            .catch(error => console.error('Error fetching reports:', error));
            */
        }

        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            fetchRecentReports();
            
            // Set current date
            const today = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('currentDate').textContent = today.toLocaleDateString('en-US', options);
        });
    </script>
</body>
</html>
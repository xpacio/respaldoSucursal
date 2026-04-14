# Hierarchical Logging System

## Overview

The GIS system implements a sophisticated hierarchical logging system using the **Strategy Pattern** for flexibility and the **Repository Pattern** for data access. This system provides structured, context-aware logging with multiple backend options.

## Architecture

### 1. Strategy Pattern for Logging Backends

The system supports multiple logging strategies through the `LoggingStrategy` interface:

```php
interface LoggingStrategy {
    public function startExecution(string $clientId, string $action, string $sourceType = 'api'): string;
    public function step(int $code, string $message): void;
    public function finish(string $status): void;
    public function enterContext(int $module, int $api, int $function, string $description): void;
    public function stepWithContext(int $step, int $result, string $message): void;
    public function exitContext(): void;
    public function hasContext(): bool;
}
```

### Available Strategies:

#### a) DatabaseLoggingStrategy
- **Purpose**: Logs to PostgreSQL database tables
- **Tables Used**: `executions`, `execution_steps`
- **Code Maps**: `log_modules`, `log_apis`, `log_functions`, `log_results`
- **Features**: Full hierarchical context support, code-to-name mapping

#### b) FileLoggingStrategy
- **Purpose**: Logs to filesystem
- **Directory**: Configurable (default: `/var/log/gis`)
- **File Format**: `execution_{ID}.log`
- **Features**: Simple text logging, human-readable format

#### c) CompositeLoggingStrategy
- **Purpose**: Combines multiple strategies
- **Use Case**: Log to both database and files simultaneously
- **Features**: Broadcasts all operations to all registered strategies

### 2. Code Structure: MMAAFFPPR Format

All log entries use a 9-digit hierarchical code format:

```
MM AA FF PP R
│  │  │  │  └─ Result (0-9)
│  │  │  └─── Step (00-99)
│  │  └────── Function (00-99)
│  └───────── API (00-99)
└──────────── Module (01-99)
```

**Example**: `201001230` = 
- Module 20 (clients)
- API 10 (client-service)
- Function 01 (create)
- Step 23 (validation)
- Result 0 (success)

### 3. Context Management

The system supports hierarchical contexts for grouping related operations:

```php
// Enter a context
$logger->enterContext(2, 30, 1, 'create_client');

// Log steps within context (automatically uses context codes)
$logger->stepWithContext(1, 0, 'Validating client ID');

// Exit context
$logger->exitContext();
```

### 4. Factory Pattern Integration

#### LoggerFactory
Creates appropriate logger based on configuration:

```php
$logger = LoggerFactory::createFromConfig($config, $db);
```

Configuration options in `config.php`:
```php
'logging' => [
    'strategy' => 'database', // 'database', 'file', or 'composite'
    'file_log_dir' => '/var/log/gis',
    'composite_strategies' => ['database', 'file']
]
```

## Database Schema

### Core Tables:

#### executions
```sql
CREATE TABLE executions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    rbfid VARCHAR(5) NOT NULL,
    action VARCHAR(100) NOT NULL,
    status VARCHAR(20),
    source_type VARCHAR(10) DEFAULT 'api',
    started_at TIMESTAMP DEFAULT NOW(),
    finished_at TIMESTAMP
);
```

#### execution_steps
```sql
CREATE TABLE execution_steps (
    id SERIAL PRIMARY KEY,
    execution_id UUID REFERENCES executions(id) ON DELETE CASCADE,
    step_code INTEGER NOT NULL,
    step_message TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
```

### Code Mapping Tables:

#### log_modules
```sql
CREATE TABLE log_modules (
    code INTEGER PRIMARY KEY,
    name VARCHAR(50) NOT NULL
);
```

#### log_apis
```sql
CREATE TABLE log_apis (
    code INTEGER PRIMARY KEY,
    name VARCHAR(50) NOT NULL
);
```

#### log_functions
```sql
CREATE TABLE log_functions (
    api_code INTEGER NOT NULL,
    code INTEGER NOT NULL,
    name VARCHAR(50) NOT NULL,
    PRIMARY KEY (api_code, code)
);
```

#### log_results
```sql
CREATE TABLE log_results (
    code INTEGER PRIMARY KEY,
    name VARCHAR(20) NOT NULL
);
```

## Usage Examples

### 1. Basic Logging
```php
$logger->startExecution('abc12', 'create_client');
$logger->step(200100010, 'Client validation passed');
$logger->finish('success');
```

### 2. Context-Aware Logging
```php
$logger->startExecution('abc12', 'create_client');
$logger->enterContext(2, 30, 1, 'create_client');

$logger->stepWithContext(1, 0, 'Validating client ID');
$logger->stepWithContext(2, 0, 'Creating Linux user');
$logger->stepWithContext(3, 0, 'Generating SSH keys');

$logger->exitContext();
$logger->finish('success');
```

### 3. Multiple Strategies
```php
// Log to both database and file
$config = [
    'logging' => [
        'strategy' => 'composite',
        'composite_strategies' => ['database', 'file'],
        'file_log_dir' => '/var/log/gis'
    ]
];

$logger = LoggerFactory::createFromConfig($config, $db);
```

## Integration with Repository Pattern

The logging system integrates with the Repository Pattern for clean data access:

```php
// DatabaseLoggingStrategy uses Database class (PDO wrapper)
$db = new Database($config['db']);
$strategy = new DatabaseLoggingStrategy($db);

// Services can be injected with repositories
$clientRepo = RepositoryFactory::getClientRepository($db);
$clientService = new ClientService($db, $logger, $system, null, null, null, $clientRepo);
```

## Testing

The system includes comprehensive tests:

```bash
# Run logging tests
php tests/LoggingTest.php

# Test specific strategies
php tests/LoggingTest.php --test=database
php tests/LoggingTest.php --test=file
php tests/LoggingTest.php --test=composite
```

## Benefits

1. **Flexibility**: Switch between database, file, or composite logging via configuration
2. **Structure**: Hierarchical code format provides clear organization
3. **Context Awareness**: Group related operations for better traceability
4. **Testability**: Strategies can be mocked for unit testing
5. **Extensibility**: Easy to add new logging strategies (syslog, Elasticsearch, etc.)
6. **Backward Compatibility**: Maintains compatibility with existing code

## UI Logging System

### Overview
The system now includes comprehensive UI logging to track user interactions in the web interface. This provides complete audit trails for user activities including page views, navigation, authentication events, and specific actions.

### Key Features

#### 1. **Source Type Separation**
- **`source_type` column**: Distinguishes between UI and API logs ('ui', 'api', 'system')
- **Filtering**: Ability to filter logs by source in purge operations
- **Audit trails**: Complete tracking of user interactions

#### 2. **User Mapping System (Option C)**
- **Table**: `user_rbfid_mapping` maps user UUIDs to 5-character rbfid codes
- **Format**: 'u1', 'u2', ..., 'u9', 'guest', 'ui', 'api', 'system'
- **Maximum**: 10 distinct user codes supported
- **Algorithm**: Database sequence-based mapping with reuse

#### 3. **UI Logging Modules**
Three new modules added to the hierarchical logging system:

| Module | Code | Name | Description |
|--------|------|------|-------------|
| UI Auth | 50 | ui-auth | Authentication events (login, logout, session) |
| UI Navigation | 51 | ui-navigation | Page views and navigation events |
| UI Actions | 52 | ui-actions | User actions (button clicks, form submissions) |

#### 4. **UI APIs and Functions**

##### UI Auth API (Module 50, API 50)
- `login` (Function 1): User login events
- `logout` (Function 2): User logout events  
- `session-check` (Function 3): Session validation
- `password-change` (Function 4): Password changes

##### UI Navigation API (Module 51, API 51)
- `page-view` (Function 1): Page view/load events
- `menu-click` (Function 2): Menu navigation
- `tab-switch` (Function 3): Tab switching
- `breadcrumb-navigate` (Function 4): Breadcrumb navigation
- `sidebar-toggle` (Function 5): Sidebar toggle

##### UI Actions API (Module 52, API 52)
- `button-click` (Function 1): Button clicks
- `form-submit` (Function 2): Form submissions
- `search` (Function 3): Search actions
- `filter` (Function 4): Filter actions
- `sort` (Function 5): Sort actions
- `export` (Function 6): Export actions
- `import` (Function 7): Import actions
- `delete` (Function 8): Delete actions
- `edit` (Function 9): Edit actions
- `create` (Function 10): Create actions

### Implementation

#### Helper Functions (`/srv/app/www/sync/ui/logging.php`)
```php
// Get or create rbfid for user
getUserRbfid(string $userId, PDO $db): string

// Get rbfid for current logged-in user
getCurrentUserRbfid(PDO $db): string

// Initialize UI logger
initUILogger(PDO $db): Logger

// Log UI events
logUIPageView(Logger $logger, string $page, string $rbfid): void
logUIAction(Logger $logger, string $action, string $rbfid, array $params = []): void
logUIAuthEvent(Logger $logger, string $event, string $rbfid, array $details = []): void
logUINavigation(Logger $logger, string $event, string $rbfid, string $from, string $to): void
```

#### JavaScript Integration
```javascript
// API client functions
API.logUIAction(action, params)  // POST /log-ui-action

// Example usage
API.logUIAction('crear-plantilla', {
    src: '/srv/pvsi/cur',
    dst: 'pvsi',
    mode: 'ro',
    auto: true
});
```

#### Frontend Integration (`sync/index.php`)
- **Page views**: Automatically logged for all protected routes
- **Authentication**: Login/logout events logged with user details
- **Actions**: JavaScript actions logged via `/log-ui-action` endpoint

### Purge Functionality

#### API Endpoint
```http
DELETE /logs/purge?olderThan=YYYY-MM-DD&sourceType=ui&rbfid=u1
```

#### Parameters
- `olderThan` (required): Date limit (YYYY-MM-DD format)
- `sourceType` (optional): Filter by source ('ui', 'api', 'system', or 'all')
- `rbfid` (optional): Filter by user code (5 characters max)

#### UI Controls (`logs.php`)
- Date picker for selecting purge date
- Source type filter dropdown
- User (rbfid) filter input
- Confirmation modal with warning

### Security & Compliance

#### Level B Logging
- **All user interactions logged**: Page views, navigation, actions
- **Form parameters included**: Excluding passwords/tokens (redacted)
- **Full usernames**: No anonymization required
- **IP and user agent**: Logged for authentication events

#### Retention Policy
- **12-month retention**: All logs kept for 12 months
- **Manual purge**: Users can purge logs via UI with filters
- **Admin-only**: Purge functionality restricted to admin users

### Example Log Entries

#### Page View
```
rbfid: u1
action: UI Page: plantillas
source_type: ui
status: success
```

#### Authentication Event
```
rbfid: u1  
action: UI Auth: login
source_type: ui
status: success
Details: {username: 'admin', ip: '192.168.1.100', user_agent: '...'}
```

#### UI Action
```
rbfid: u1
action: UI Action: crear-plantilla
source_type: ui
status: success
Details: {src: '/srv/pvsi/cur', dst: 'pvsi', mode: 'ro', auto: true}
```

## Future Enhancements

1. **Additional Strategies**:
   - SyslogStrategy for system logging
   - ElasticsearchStrategy for distributed logging
   - CloudWatchStrategy for AWS integration

2. **Performance Optimizations**:
   - Batch logging for high-volume operations
   - Async logging with message queues

3. **Monitoring Integration**:
   - Real-time dashboard for execution monitoring
   - Alerting on error patterns
   - Performance metrics collection

4. **UI Logging Enhancements**:
   - Real-time log streaming to UI
   - Advanced filtering and search
   - User behavior analytics
   - Automated report generation
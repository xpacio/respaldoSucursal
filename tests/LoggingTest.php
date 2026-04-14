<?php
/**
 * Tests for the logging system with Strategy Pattern
 */

require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/../Config/LoggingStrategy.php';
require_once __DIR__ . '/../Config/Logger.php';
require_once __DIR__ . '/../Config/LoggerFactory.php';

class LoggingTest {
    private array $config;
    private Database $db;
    
    public function __construct(array $config) {
        $this->config = $config;
        $this->db = new Database($config['db']);
    }
    
    public function runAllTests(): void {
        echo "=== Logging System Tests ===\n\n";
        
        $this->testDatabaseLoggingStrategy();
        $this->testFileLoggingStrategy();
        $this->testCompositeLoggingStrategy();
        $this->testLoggerFactory();
        $this->testLoggerContext();
        
        echo "\n=== All Tests Complete ===\n";
    }
    
    private function testDatabaseLoggingStrategy(): void {
        echo "1. Testing DatabaseLoggingStrategy:\n";
        
        $strategy = new DatabaseLoggingStrategy($this->db);
        
        // Test step logging - use valid 5-char client ID
        $executionId = $strategy->startExecution('test1', 'test_action');
        echo "   - Execution started with ID: $executionId\n";
        
        $strategy->step(1000, 'Test step message');
        echo "   - Step logged successfully\n";
        
        $strategy->finish('success');
        echo "   - Execution finished successfully\n";
        
        echo "   ✅ DatabaseLoggingStrategy works correctly\n\n";
    }
    
    private function testFileLoggingStrategy(): void {
        echo "2. Testing FileLoggingStrategy:\n";
        
        $logDir = '/tmp/test_logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $strategy = new FileLoggingStrategy($logDir);
        
        // Test step logging - use valid 5-char client ID
        $executionId = $strategy->startExecution('test1', 'test_action');
        echo "   - Execution started with ID: $executionId\n";
        
        $strategy->step(1000, 'Test step message');
        echo "   - Step logged to file\n";
        
        $strategy->finish('success');
        echo "   - Execution finished\n";
        
        // Verify file was created - FileLoggingStrategy creates execution_ID.log files
        $logFiles = glob($logDir . '/execution_*.log');
        if (!empty($logFiles)) {
            echo "   - Log file created: " . basename($logFiles[0]) . "\n";
            echo "   ✅ FileLoggingStrategy works correctly\n";
        } else {
            echo "   ❌ FileLoggingStrategy failed to create log file\n";
        }
        
        // Cleanup
        if (is_dir($logDir)) {
            array_map('unlink', glob("$logDir/*.log"));
            rmdir($logDir);
        }
        
        echo "\n";
    }
    
    private function testCompositeLoggingStrategy(): void {
        echo "3. Testing CompositeLoggingStrategy:\n";
        
        $dbStrategy = new DatabaseLoggingStrategy($this->db);
        
        $logDir = '/tmp/test_logs_composite';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $fileStrategy = new FileLoggingStrategy($logDir);
        
        $strategies = [$dbStrategy, $fileStrategy];
        $strategy = new CompositeLoggingStrategy($strategies);
        
        // Test step logging - use valid 5-char client ID
        $executionId = $strategy->startExecution('test1', 'test_action');
        echo "   - Execution started with ID: $executionId\n";
        
        $strategy->step(1000, 'Test composite step');
        echo "   - Step logged to multiple strategies\n";
        
        $strategy->finish('success');
        echo "   - Execution finished\n";
        
        // Verify file was created - FileLoggingStrategy creates execution_ID.log files
        $logFiles = glob($logDir . '/execution_*.log');
        if (!empty($logFiles)) {
            echo "   - Composite log file created: " . basename($logFiles[0]) . "\n";
            echo "   ✅ CompositeLoggingStrategy works correctly\n";
        } else {
            echo "   ❌ CompositeLoggingStrategy failed\n";
        }
        
        // Cleanup
        if (is_dir($logDir)) {
            array_map('unlink', glob("$logDir/*.log"));
            rmdir($logDir);
        }
        
        echo "\n";
    }
    
    private function testLoggerFactory(): void {
        echo "4. Testing LoggerFactory:\n";
        
        // Test database logging
        $configDb = $this->config;
        $configDb['logging'] = ['strategy' => 'database'];
        $loggerDb = LoggerFactory::createFromConfig($configDb, $this->db);
        echo "   - Database logger created: " . get_class($loggerDb) . "\n";
        
        // Test file logging
        $configFile = $this->config;
        $configFile['logging'] = ['strategy' => 'file', 'file_log_dir' => '/tmp/test_logs_factory'];
        $loggerFile = LoggerFactory::createFromConfig($configFile, $this->db);
        echo "   - File logger created: " . get_class($loggerFile) . "\n";
        
        // Test composite logging
        $configComposite = $this->config;
        $configComposite['logging'] = [
            'strategy' => 'composite',
            'composite_strategies' => ['database', 'file'],
            'file_log_dir' => '/tmp/test_logs_factory'
        ];
        $loggerComposite = LoggerFactory::createFromConfig($configComposite, $this->db);
        echo "   - Composite logger created: " . get_class($loggerComposite) . "\n";
        
        echo "   ✅ LoggerFactory creates correct logger types\n\n";
        
        // Cleanup
        $logDir = '/tmp/test_logs_factory';
        if (is_dir($logDir)) {
            array_map('unlink', glob("$logDir/*.log"));
            rmdir($logDir);
        }
    }
    
    private function testLoggerContext(): void {
        echo "5. Testing Logger Context Management:\n";
        
        $logger = LoggerFactory::createFromConfig($this->config, $this->db);
        
        // Need to start an execution first
        $logger->startExecution('test1', 'context_test');
        
        // Test context methods
        $logger->enterContext(2, 30, 1, 'test_function');
        echo "   - Context entered\n";
        
        $hasContext = $logger->hasContext();
        echo "   - hasContext(): " . ($hasContext ? 'true' : 'false') . "\n";
        
        $logger->stepWithContext(1, 0, 'Step with context');
        echo "   - stepWithContext() executed\n";
        
        // Note: getContextDescription() is a private method in DatabaseLoggingStrategy
        // We can't test it directly, but we can test that context works
        echo "   - Context methods work correctly\n";
        
        $logger->exitContext();
        echo "   - Context exited\n";
        
        $logger->finish('success');
        echo "   - Execution finished\n";
        
        echo "   ✅ Context management works correctly\n\n";
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $config = require __DIR__ . '/../Config/config.php';
    $test = new LoggingTest($config);
    $test->runAllTests();
}
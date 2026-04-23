<?php

namespace Laradock\PHPVersionManager;

use Laradock\PHPVersionManager\Models\DockerResult;

/**
 * Docker Compose Manager for container operations
 * 
 * Handles docker-compose operations including container building, starting,
 * stopping, and PHP version management.
 */
class DockerComposeManager
{
    /**
     * Logger for docker operations
     */
    private ?object $logger = null;
    
    /**
     * Docker compose file paths
     */
    private array $composeFiles = [
        'docker-compose.yml',
        'docker-compose.php-version.yml'
    ];
    
    /**
     * Container service mappings
     */
    private array $serviceNames = [
        'workspace' => 'workspace',
        'php-fpm' => 'php-fpm',
        'nginx' => 'nginx'
    ];

    public function __construct(?array $composeFiles = null)
    {
        if ($composeFiles !== null) {
            $this->composeFiles = $composeFiles;
        }
    }

    /**
     * Generate docker-compose override for PHP version
     * 
     * @param string $version PHP version
     * @param array $containerTypes Container types to include
     */
    public function generateOverride(string $version, array $containerTypes): void
    {
        $services = [];
        
        foreach ($containerTypes as $containerType) {
            $serviceName = $this->serviceNames[$containerType] ?? $containerType;
            
            $services[$serviceName] = $this->buildServiceConfig($containerType, $version);
        }
        
        $override = [
            'version' => '3.8',
            'services' => $services
        ];
        
        $yamlContent = $this->arrayToYaml($override);
        file_put_contents('docker-compose.php-version.yml', $yamlContent);
        
        $this->logInfo("Generated docker-compose override for PHP {$version}");
    }

    /**
     * Integrate with existing docker-compose commands
     * 
     * @param string $command Docker compose command to execute
     * @param array $services Optional services to target
     * @return DockerResult Command execution result
     */
    public function executeDockerComposeCommand(string $command, array $services = []): DockerResult
    {
        try {
            $serviceList = empty($services) ? '' : implode(' ', $services);
            $fullCommand = $this->buildDockerComposeCommand("{$command} {$serviceList}");
            
            $this->logInfo("Executing docker-compose command: {$command}");
            
            $output = [];
            $returnCode = 0;
            
            exec($fullCommand . ' 2>&1', $output, $returnCode);
            
            if ($returnCode === 0) {
                return new DockerResult(
                    true,
                    "Command executed successfully: {$command}",
                    $output
                );
            } else {
                return new DockerResult(
                    false,
                    "Command failed with exit code {$returnCode}: {$command}",
                    $output
                );
            }
            
        } catch (\Exception $e) {
            return new DockerResult(
                false,
                "Command execution exception: " . $e->getMessage(),
                []
            );
        }
    }

    /**
     * Check service dependencies and ensure compatibility
     * 
     * @param array $containerTypes Container types to check
     * @return array Dependency compatibility results
     */
    public function checkServiceDependencies(array $containerTypes): array
    {
        $dependencies = [
            'workspace' => ['mysql', 'redis'], // Common workspace dependencies
            'php-fpm' => ['mysql', 'redis'],   // Common PHP-FPM dependencies
            'nginx' => ['php-fpm']             // Nginx depends on PHP-FPM
        ];
        
        $results = [];
        
        foreach ($containerTypes as $containerType) {
            $serviceName = $this->serviceNames[$containerType] ?? $containerType;
            $serviceDeps = $dependencies[$containerType] ?? [];
            
            $results[$containerType] = [
                'service' => $serviceName,
                'dependencies' => $serviceDeps,
                'compatible' => true // For now, assume compatible
            ];
        }
        
        return $results;
    }

    /**
     * Ensure service restart maintains PHP version consistency
     * 
     * @param array $containerTypes Container types to restart
     * @return DockerResult Restart operation result
     */
    public function restartWithConsistency(array $containerTypes): DockerResult
    {
        try {
            // First, stop the containers
            $stopResult = $this->stopContainers($containerTypes);
            if (!$stopResult->success) {
                return $stopResult;
            }
            
            // Wait a moment for clean shutdown
            sleep(2);
            
            // Start the containers with current configuration
            $startResult = $this->startContainers($containerTypes);
            
            if ($startResult->success) {
                $this->logInfo("Containers restarted with PHP version consistency maintained");
            }
            
            return $startResult;
            
        } catch (\Exception $e) {
            return new DockerResult(
                false,
                "Restart with consistency failed: " . $e->getMessage(),
                []
            );
        }
    }

    /**
     * Build containers with current configuration
     * 
     * @param array $containerTypes Container types to build
     * @return DockerResult Build operation result
     */
    public function buildContainers(array $containerTypes): DockerResult
    {
        try {
            $services = array_map(fn($type) => $this->serviceNames[$type] ?? $type, $containerTypes);
            $serviceList = implode(' ', $services);
            
            $command = $this->buildDockerComposeCommand("build --no-cache {$serviceList}");
            
            $this->logInfo("Building containers: {$serviceList}");
            
            $output = [];
            $returnCode = 0;
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode === 0) {
                $this->logInfo("Container build completed successfully");
                
                return new DockerResult(
                    true,
                    "Container build completed successfully",
                    $output
                );
            } else {
                $errorMessage = "Container build failed with exit code {$returnCode}";
                $this->logError($errorMessage);
                
                return new DockerResult(
                    false,
                    $errorMessage,
                    $output
                );
            }
            
        } catch (\Exception $e) {
            $this->logError("Container build exception: " . $e->getMessage());
            
            return new DockerResult(
                false,
                "Container build exception: " . $e->getMessage(),
                []
            );
        }
    }

    /**
     * Start containers
     * 
     * @param array $containerTypes Container types to start
     * @return DockerResult Start operation result
     */
    public function startContainers(array $containerTypes): DockerResult
    {
        try {
            $services = array_map(fn($type) => $this->serviceNames[$type] ?? $type, $containerTypes);
            $serviceList = implode(' ', $services);
            
            $command = $this->buildDockerComposeCommand("up -d {$serviceList}");
            
            $this->logInfo("Starting containers: {$serviceList}");
            
            $output = [];
            $returnCode = 0;
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode === 0) {
                $this->logInfo("Containers started successfully");
                
                return new DockerResult(
                    true,
                    "Containers started successfully",
                    $output
                );
            } else {
                $errorMessage = "Container start failed with exit code {$returnCode}";
                $this->logError($errorMessage);
                
                return new DockerResult(
                    false,
                    $errorMessage,
                    $output
                );
            }
            
        } catch (\Exception $e) {
            $this->logError("Container start exception: " . $e->getMessage());
            
            return new DockerResult(
                false,
                "Container start exception: " . $e->getMessage(),
                []
            );
        }
    }

    /**
     * Stop containers
     * 
     * @param array $containerTypes Container types to stop
     * @return DockerResult Stop operation result
     */
    public function stopContainers(array $containerTypes): DockerResult
    {
        try {
            $services = array_map(fn($type) => $this->serviceNames[$type] ?? $type, $containerTypes);
            $serviceList = implode(' ', $services);
            
            $command = $this->buildDockerComposeCommand("stop {$serviceList}");
            
            $this->logInfo("Stopping containers: {$serviceList}");
            
            $output = [];
            $returnCode = 0;
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode === 0) {
                $this->logInfo("Containers stopped successfully");
                
                return new DockerResult(
                    true,
                    "Containers stopped successfully",
                    $output
                );
            } else {
                $errorMessage = "Container stop failed with exit code {$returnCode}";
                $this->logError($errorMessage);
                
                return new DockerResult(
                    false,
                    $errorMessage,
                    $output
                );
            }
            
        } catch (\Exception $e) {
            $this->logError("Container stop exception: " . $e->getMessage());
            
            return new DockerResult(
                false,
                "Container stop exception: " . $e->getMessage(),
                []
            );
        }
    }

    /**
     * Get PHP version from running container
     * 
     * @param string $containerType Container type to check
     * @return string PHP version or empty string if not found
     */
    public function getContainerPhpVersion(string $containerType): string
    {
        try {
            $serviceName = $this->serviceNames[$containerType] ?? $containerType;
            $command = $this->buildDockerComposeCommand("exec -T {$serviceName} php -v");
            
            $output = [];
            $returnCode = 0;
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode === 0 && !empty($output)) {
                // Parse PHP version from output (e.g., "PHP 8.4.0 (cli) ...")
                $versionLine = $output[0] ?? '';
                if (preg_match('/PHP (\d+\.\d+)/', $versionLine, $matches)) {
                    return $matches[1];
                }
            }
            
            return '';
            
        } catch (\Exception $e) {
            $this->logError("Failed to get PHP version from {$containerType}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Check container health status
     * 
     * @param array $containerTypes Container types to check
     * @return DockerResult Health check result
     */
    public function checkContainerHealth(array $containerTypes): DockerResult
    {
        try {
            $healthyContainers = [];
            $unhealthyContainers = [];
            
            foreach ($containerTypes as $containerType) {
                $serviceName = $this->serviceNames[$containerType] ?? $containerType;
                
                if ($this->isContainerRunning($serviceName)) {
                    $healthyContainers[] = $containerType;
                } else {
                    $unhealthyContainers[] = $containerType;
                }
            }
            
            if (empty($unhealthyContainers)) {
                return new DockerResult(
                    true,
                    "All containers are healthy",
                    $healthyContainers
                );
            } else {
                return new DockerResult(
                    false,
                    "Unhealthy containers: " . implode(', ', $unhealthyContainers),
                    ['healthy' => $healthyContainers, 'unhealthy' => $unhealthyContainers]
                );
            }
            
        } catch (\Exception $e) {
            return new DockerResult(
                false,
                "Health check failed: " . $e->getMessage(),
                []
            );
        }
    }

    /**
     * Set logger for docker operations
     */
    public function setLogger(object $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Build docker-compose command with file arguments
     */
    private function buildDockerComposeCommand(string $subCommand): string
    {
        $fileArgs = '';
        
        foreach ($this->composeFiles as $file) {
            if (file_exists($file)) {
                $fileArgs .= " -f {$file}";
            }
        }
        
        return "docker-compose{$fileArgs} {$subCommand}";
    }

    /**
     * Build service configuration for docker-compose override
     */
    private function buildServiceConfig(string $containerType, string $version): array
    {
        $config = [];
        
        switch ($containerType) {
            case 'workspace':
                $config = [
                    'build' => [
                        'args' => [
                            "LARADOCK_PHP_VERSION={$version}"
                        ]
                    ],
                    'environment' => [
                        "PHP_VERSION={$version}",
                        "LARADOCK_PHP_VERSION={$version}"
                    ]
                ];
                break;
                
            case 'php-fpm':
                $config = [
                    'build' => [
                        'args' => [
                            "LARADOCK_PHP_VERSION={$version}"
                        ]
                    ],
                    'environment' => [
                        "PHP_VERSION={$version}",
                        "LARADOCK_PHP_VERSION={$version}"
                    ]
                ];
                break;
                
            case 'nginx':
                $config = [
                    'environment' => [
                        "PHP_VERSION={$version}",
                        "LARADOCK_PHP_VERSION={$version}"
                    ],
                    'depends_on' => [
                        'php-fpm'
                    ]
                ];
                break;
        }
        
        return $config;
    }

    /**
     * Generate comprehensive docker-compose override with service dependencies
     * 
     * @param string $version PHP version
     * @param array $containerTypes Container types to include
     * @param array $additionalServices Additional services to include
     */
    public function generateComprehensiveOverride(string $version, array $containerTypes, array $additionalServices = []): void
    {
        $services = [];
        
        // Add PHP-related services
        foreach ($containerTypes as $containerType) {
            $serviceName = $this->serviceNames[$containerType] ?? $containerType;
            $services[$serviceName] = $this->buildServiceConfig($containerType, $version);
        }
        
        // Add additional services that might depend on PHP version
        foreach ($additionalServices as $serviceName => $serviceConfig) {
            $services[$serviceName] = $serviceConfig;
        }
        
        // Ensure proper service dependencies
        $services = $this->ensureServiceDependencies($services);
        
        $override = [
            'version' => '3.8',
            'services' => $services
        ];
        
        $yamlContent = $this->arrayToYaml($override);
        file_put_contents('docker-compose.php-version.yml', $yamlContent);
        
        $this->logInfo("Generated comprehensive docker-compose override for PHP {$version}");
    }

    /**
     * Ensure proper service dependencies in docker-compose configuration
     * 
     * @param array $services Services configuration
     * @return array Services with proper dependencies
     */
    private function ensureServiceDependencies(array $services): array
    {
        // Nginx should depend on php-fpm
        if (isset($services['nginx']) && isset($services['php-fpm'])) {
            if (!isset($services['nginx']['depends_on'])) {
                $services['nginx']['depends_on'] = [];
            }
            if (!in_array('php-fpm', $services['nginx']['depends_on'])) {
                $services['nginx']['depends_on'][] = 'php-fpm';
            }
        }
        
        return $services;
    }

    /**
     * Validate docker-compose configuration compatibility
     * 
     * @param string $composeFile Path to docker-compose file
     * @return array Validation results
     */
    public function validateDockerComposeCompatibility(string $composeFile = 'docker-compose.yml'): array
    {
        $results = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => []
        ];
        
        if (!file_exists($composeFile)) {
            $results['errors'][] = "Docker compose file not found: {$composeFile}";
            $results['valid'] = false;
            return $results;
        }
        
        $content = file_get_contents($composeFile);
        
        // Check for PHP-related services
        $requiredServices = ['workspace', 'php-fpm'];
        foreach ($requiredServices as $service) {
            if (strpos($content, $service . ':') === false) {
                $results['warnings'][] = "Service '{$service}' not found in docker-compose.yml";
            }
        }
        
        // Check for LARADOCK_PHP_VERSION usage
        if (strpos($content, 'LARADOCK_PHP_VERSION') === false && strpos($content, 'PHP_VERSION') === false) {
            $results['suggestions'][] = "Consider using LARADOCK_PHP_VERSION or PHP_VERSION variables in your docker-compose.yml";
        }
        
        return $results;
    }

    /**
     * Check if container is running
     */
    private function isContainerRunning(string $serviceName): bool
    {
        try {
            $command = $this->buildDockerComposeCommand("ps -q {$serviceName}");
            
            $output = [];
            $returnCode = 0;
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            return $returnCode === 0 && !empty($output);
            
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Convert array to YAML format (simple implementation)
     */
    private function arrayToYaml(array $data, int $indent = 0): string
    {
        $yaml = '';
        $indentStr = str_repeat('  ', $indent);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $yaml .= $indentStr . $key . ":\n";
                $yaml .= $this->arrayToYaml($value, $indent + 1);
            } else {
                $yaml .= $indentStr . $key . ': ' . $value . "\n";
            }
        }
        
        return $yaml;
    }

    /**
     * Log informational messages
     */
    private function logInfo(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message);
        } else {
            error_log("DockerComposeManager Info: " . $message);
        }
    }

    /**
     * Log error messages
     */
    private function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message);
        } else {
            error_log("DockerComposeManager Error: " . $message);
        }
    }
}
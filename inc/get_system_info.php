<?php

    class SystemInfoWindows
    {
    /**
     * Determine whether the specified file exists in the specified path, and create if not
     * @param string $fileName file name
     * @param string $content  Document content
     * @return string Return to file path
     */
    private function getFilePath($fileName, $content)
    {
        $path = dirname(__FILE__) . "\\$fileName";
        if (!file_exists($path)) {
        file_put_contents($path, $content);
        }
        return $path;
    }

    /**
     * Get the vbs file generating function of cpu utilization
     * @return string Return to vbs file path
     */
    private function getCupUsageVbsPath()
    {
        return $this->getFilePath(
        'cpu_usage.vbs',
        "On Error Resume Next
        Set objProc = GetObject(\"winmgmts:\\\\.\\root\cimv2:win32_processor='cpu0'\")
        WScript.Echo(objProc.LoadPercentage)"
        );
    }

    /**
     * Get total memory and available physical memory JSON vbs file generation function
     * @return string Return to vbs file path
     */
    private function getMemoryUsageVbsPath()
    {
        return $this->getFilePath(
        'memory_usage.vbs',
        "On Error Resume Next
        Set objWMI = GetObject(\"winmgmts:\\\\.\\root\cimv2\")
        Set colOS = objWMI.InstancesOf(\"Win32_OperatingSystem\")
        For Each objOS in colOS
            Wscript.Echo(\"{\"\"TotalVisibleMemorySize\"\":\" & objOS.TotalVisibleMemorySize & \",\"\"FreePhysicalMemory\"\":\" & objOS.FreePhysicalMemory & \"}\")
        Next"
        );
    }

    /**
     * Get CPU usage
     * @return Number
     */
    public function getCpuUsage()
    {
        $path = $this->getCupUsageVbsPath();
        exec("cscript -nologo $path", $usage);
        return $usage[0];
    }

    /**
     * Get memory usage array
     * @return array
     */
    public function getMemoryUsage()
    {
        $path = $this->getMemoryUsageVbsPath();
        exec("cscript -nologo $path", $usage);
        $memory = json_decode($usage[0], true);
        $memory['usage'] = Round((($memory['TotalVisibleMemorySize'] - $memory['FreePhysicalMemory']) / $memory['TotalVisibleMemorySize']) * 100);
        return $memory;
    }
    }

?>
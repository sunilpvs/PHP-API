<?php
class FileHelper {
    public static function getVendorUploadFolder($vendor_id, $referenceId) {
        $baseDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/vms/';
        $vendorFolder = $baseDir . "vendor_" . $vendor_id . "_" . $referenceId . "/";

        if (!is_dir($vendorFolder)) {
            mkdir($vendorFolder, 0777, true);
        }
        return $vendorFolder;
    }
}

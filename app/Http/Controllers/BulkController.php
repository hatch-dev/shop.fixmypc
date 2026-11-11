<?php

namespace App\Http\Controllers;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Product;
use Illuminate\Http\Request;


class BulkController extends ControllerHelper
{

    public function exportData(Request $request)
    {
        try {

            if ($can = Utils::userCan($this->user, 'bulk_upload.view')) {
                return $can;
            }

            $lang = $request->header('language');

            $filename = 'products-'. date('Y-m-d-H_i_s') .'.xlsx';

            $products = new ProductsExport($lang);

           return \Excel::download($products, $filename, \Maatwebsite\Excel\Excel::XLSX, [
                'Access-Control-Expose-Headers'=> 'Content-Disposition',
                'Content-Disposition' => 'attachment; filename="' . $filename .'"',
            ]);

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function importData(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'bulk_upload.edit')) {
                return $can;
            }

            $lang = $request->header('language');

            $file = $request['file'];

            $products = new ProductsImport($lang);

            \Excel::import($products, $file);

            /*
             * The valid rows are in; the rest are listed by sheet row with the
             * reason, for the bulk upload page to show (ADC-009). A file with
             * no bad rows answers as before, plus the count.
             */
            $failed = $products->failed;

            $message = __('lang.bulk_import_result', [
                'imported' => $products->imported, 'failed' => count($failed),
            ], $lang);

            if ($failed) {
                $message .= ' ' . collect($failed)->take(5)->map(function ($f) use ($lang) {
                    return __('lang.bulk_row', ['row' => $f['row']], $lang) . ': ' . $f['reason'];
                })->implode('; ') . (count($failed) > 5 ? '; …' : '');
            }

            return response()->json(new Response($request->token, [
                'imported' => $products->imported,
                'failed' => $failed,
            ], 200, $message));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;

class TemplateController extends ControllerHelper
{
	
	
    public function all(Request $request)
    {
		
        try {
            $lang = $request->header('language');

            $query = Template::orderBy('templates.' . $request->orderby, $request->type);

           /* if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            } */


           if ($request->q) {
                $query = $query->where('templates.title', 'LIKE', "%{$request->q}%");
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            return response()->json(new Response($request->token, $data));
		} 
		catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            $ids = explode(",", $id);
			
			Template::find($id)->delete();

            return response()->json(new Response($request->token, true));
			
			} 
			catch (\Exception $ex) {
				return response()->json(Validation::error($request->token, $ex->getMessage()));
			}
    }

    public function find(Request $request,$id)
    {

        $lang = $request->header('language');
		
        $template = Template::find($id);

        return response()->json(new Response($request->token, $template));
    }


    public function action(Request $request)
    {
		
        $lang = $request->header('language');

		$validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string'
        ]);
		
		if(isset($request->id)){
			$template = Template::findOrFail($request->id);
			 $template->update($validated);
		}
		else{
			$template=Template::create($validated);
		}
        
        
        return response()->json(new Response($request->token, $template, 200));

    } 
	
	public function allSimple(Request $request)
	{
		$query = Template::query();
		
		return $query->get();
	}
	
	
}

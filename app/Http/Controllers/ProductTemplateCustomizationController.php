<?php

namespace App\Http\Controllers;

use App\Models\ProductTemplateCustomization;
use App\Models\Template;
use Illuminate\Http\Request;


class ProductTemplateCustomizationController extends Controller
{
    // ProductTemplateCustomizationController.php

	public function index($productId)
	{
		return ProductTemplateCustomization::with('template')
			->where('product_id', $productId)
			->get();
	}

	public function bulkStore(Request $request, $productId)
	{
		$validated = $request->validate([
			'customizations' => 'required|array',
			'customizations.*.template_id' => 'required|exists:templates,id',
			'customizations.*.custom_content' => 'required|string'
		]);

		// Delete existing customizations for this product
		ProductTemplateCustomization::where('product_id', $productId)->delete();

		// Create new customizations
		$savedCustomizations = [];
		foreach ($validated['customizations'] as $customization) {
			$template_id=$customization['template_id'];
			$template_name=Template::where("id",$template_id)->pluck('title')[0];
			
			$savedCustomizations[] = ProductTemplateCustomization::create([
				'product_id' => $productId,
				'template_id' => $customization['template_id'],
				'name' => $template_name,
				'custom_content' => $customization['custom_content']
			]);
		}

		return response()->json(
			ProductTemplateCustomization::with('template')
				->whereIn('id', collect($savedCustomizations)->pluck('id'))
				->get()
		);
	}

}
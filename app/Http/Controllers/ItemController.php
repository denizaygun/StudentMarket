<?php

namespace App\Http\Controllers;

use App\Category;
use App\Image;
use App\Item;
use App\SavedItem;
use App\Tag;
use App\ItemTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * @resource Items
 */
class ItemController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Provides a response to API requests in JSON with a consistent formatting
     */
    public function apiResponse($success, $message, $data, $status = 200)
    {
        return response()->json(['success' => $success, 'message' => $message, 'data' => $data], $status);
    }

    /**
     * All Items
     *
     * Returns all available items
     */
    public function index(Request $request, $category = null)
    {
        if ($category == null) {
            $data = ['items' => Item::with('category')->orderBy('created_at')->paginate(15)];

            if ($request->is('api/*'))
               return $this->apiResponse(true, 'Success (items index with no category)', $data);

            return view('items.index', $data); // View all items in all categories
        }

        $items = Item::whereHas('category', function ($query) use ($category) { // Limiting our results based on whether a relationship to the specific category exists or not
            $query->where('slug', $category);
        })->paginate(15);

        $data = ['items' => $items, 'category' => Category::where('slug', $category)->first()->name];

        if ($request->is('api/*'))
            return $this->apiResponse(true, 'Success (items index)', $data);

        return view('items.index', $data);
    }

    public function createItem(Request $request)
    {
        if ($request->type == "sell") {
            $request->validate([
                'category_id' => 'required|exists:categories,id',
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:255',
                'type' => 'required|string',
                'price' => 'required|integer',
                'trade' => 'nullable|string',
                'tags' => 'nullable|string'
            ]);
        } else if ($request->type == "swap") {
            $request->validate([
                'category_id' => 'required|exists:categories,id',
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:255',
                'type' => 'required|string',
                'price' => 'nullable|integer',
                'trade' => 'required|string',
                'tags' => 'nullable|string'
            ]);
        } else {
            $request->validate([
                'category_id' => 'required|exists:categories,id',
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:255',
                'type' => 'required|string',
                'price' => 'required|integer',
                'trade' => 'required|string',
                'tags' => 'nullable|string'
            ]);
        }

        // ADD ITEM
        $item = new Item();
        $item->category_id = $request->category_id;
        $item->user_id = Auth::id();
        $item->name = $request->name;
        $item->description = $request->description;
        $item->type = $request->type;
        $item->price = $request->price;
        $item->trade = $request->trade;
        $item->save();

        // ADD TAGS
        if (trim($request->tags)) {
            $tagString = trim($request->tags);
            $tagArray = explode(" ", $tagString); // SPLIT AND TRIM TAGS

            foreach ($tagArray as $tag) {
                $tag = trim($tag);

                // ADD TAG TO TABLE IF DOESN'T EXIST
                if (!Tag::where('name', $tag)->count()) {
                    $newTag = new Tag();
                    $newTag->name = $tag;
                    $newTag->save();
                }

                // LINK ITEM TAG TO TAG TABLE
                $newItemTag = new ItemTag();
                $newItemTag->tag_id = Tag::where('name', $tag)->first()->id;
                $newItemTag->item_id = $item->id;
                $newItemTag->save();
            }
        }

//        ADD IMAGES
        if ($request->images)
            $this->storeImages($item, $request->file('images'));

        // DISPLAY SUCCESS MESSAGE
        $request->session()->flash('success', 'Successfully added item.');

        $authorised = ($item->user_id == Auth::id()) ? true : false; // Checks to see if the item belongs to the authenticated user
        return view('items.read', ['item' => $item, 'category' => null, 'authorised' => $authorised]);
    }

    /**
     * Individual Item
     *
     * Returns an individual item
     */
    public function readItem(Request $request, $category = null, $id = null)
    {
        if ($category == "update")
           return $this->editItem($id);
        if ($category == "sold")
            return $this->soldItem($id);

        $item = Item::with('category', 'images', 'user')->find($id);
        $category = $category ?: $item->category->slug; // If the category is not passed through then retrieve it from the item
        $authorised = ($item->user_id == Auth::id()) ? true : false; // Checks to see if the item belongs to the authenticated user

        $saved = false;
        if (Auth::id() != $item->seller_id) {
            if (SavedItem::where(['item_id' => $item->id, 'user_id' => Auth::id()])->count()) {
                $saved = true;
            }
        }

        $data = ['item' => $item, 'category' => $category, 'authorised' => $authorised, 'saved' => $saved];

        if ($request->is('api/*'))
            return $this->apiResponse(true, 'Success (individual item)', $data);

        return view('items.read', $data);

    }

    // FUNCTION USED TO OPEN UPDATE VIEW - DO NOT DELETE
    public function editItem($id = null)
    {
        $item = Item::find($id);
        $tagsArray = ItemTag::where('item_id', $item->id)->get();
        $tags = [];
        foreach ($tagsArray as $itemTag) {
            $tag = Tag::find($itemTag->tag_id);
            array_push($tags, $tag->name);
        }

        $authorised = ($item->user_id == Auth::id()) ? true : false; // Checks to see if the item belongs to the authenticated user

        return view('items.update', ['item' => $item, 'authorised' => $authorised, 'tags' => $tags]);
    }

    // SAVE ITEM
    public function save(Request $request, $id = null)
    {
        $item = Item::find($id);
        $category = $item->category;
        $saved = false;

        if (Auth::id() != $item->seller_id) {
            if (!SavedItem::where(['item_id' => $item->id, 'user_id' => Auth::id()])->count()) {
                $savedItem = new SavedItem();
                $savedItem->item_id = $item->id;
                $savedItem->user_id = Auth::id();
                $savedItem->save();
                $saved = true;
                $request->session()->flash('success', 'Item has been saved!');
            } else {
                $savedID = SavedItem::where(['item_id' => $item->id, 'user_id' => Auth::id()])->first();
                $savedItem = SavedItem::find($savedID->id);
                $savedItem->delete();
            }
        }



        return redirect()->action(
            'ItemController@readItem', ['category' => $category, 'id' => $id, 'saved' => $saved]
        );
    }

    public function savedItems(Request $request)
    {
        $user_id = Auth::id();
        $items = Item::whereHas('saved_items', function ($q) use($user_id){
            $q->where('user_id', $user_id);
        })->paginate(15);
        $saved = true;

        $data = ['items' => $items, 'saved' => $saved];

        return view('items.index', $data);
    }


    public function updateItem(Request $request, $id = null)
    {
        if ($request->type == "sell") {
            $request->validate([
                'category_id' => 'required|exists:categories,id',
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:255',
                'type' => 'required|string',
                'price' => 'required|integer',
                'trade' => 'nullable|string',
                'tags' => 'nullable|string'
            ]);
        } else if ($request->type == "swap") {
            $request->validate([
                'category_id' => 'required|exists:categories,id',
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:255',
                'type' => 'required|string',
                'price' => 'nullable|integer',
                'trade' => 'required|string',
                'tags' => 'nullable|string'
            ]);
        } else {
            $request->validate([
                'category_id' => 'required|exists:categories,id',
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:255',
                'type' => 'required|string',
                'price' => 'required|integer',
                'trade' => 'required|string',
                'tags' => 'nullable|string'
            ]);
        }

        // UPDATE ITEM
        $item = Item::find($id);
        $item->name = $request->input('name');
        $item->description = $request->description;
        $item->category_id = $request->category_id;

        $item->type = $request->type;
        $item->price = $request->price;
        $item->trade = $request->trade;
        if ($request->sold) { // if 'sold' checkbox is checked
            $item->sold = true;
        } else {
            $item->sold = false;
        }
        $item->save();

        // UPDATE TAGS
        if (trim($request->tags)) {
            $tagString = trim($request->tags);
            $tagArray = explode(" ", $tagString); // SPLIT AND TRIM TAGS

            $itemTagList = ItemTag::all();
            foreach ($itemTagList as $itemTag) {
                if ($itemTag->item_id == $item->id) $itemTag->delete(); // REMOVE OLD TAGS
            }

            foreach ($tagArray as $tag) {
                $tag = trim($tag);

                // ADD TAG TO TABLE IF DOESN'T EXIST
                if (!Tag::where('name', $tag)->count()) {
                    $newTag = new Tag();
                    $newTag->name = $tag;
                    $newTag->save();
                }

                // LINK ITEM TAG TO TAG TABLE
                $newItemTag = new ItemTag();
                $newItemTag->tag_id = Tag::where('name', $tag)->first()->id;
                $newItemTag->item_id = $item->id;
                $newItemTag->save();
            }
        }

        // UPDATED IMAGES
        if ($request->images)
            $this->storeImages($item, $request->file('images'));

        $category = Category::find($item->category_id)->slug;

        $request->session()->flash('success', 'Successfully updated your item.');

        return redirect()->action(
            'ItemController@readItem', ['category' => $category, 'id' => $id]
        );//->with('status', 'Successfully updated your item!');
    }

    public function removeItem($id)
    {
        $item = Item::find($id);

        $authorised = ($item->user_id == Auth::id()) ? true : false; // Checks to see if the item belongs to the authenticated user

        // only delete if user is authorised
        if ($authorised) {
            $item->delete();
        }

        return redirect()->action('ItemController@index');
    }

    public function storeImages($item, $images)
    {
        foreach ($images as $image) {
            $img = new Image();
            $img->item_id = $item->id;
            $img->path = Storage::putFile("/item/{$item->id}", $image);
            $img->save();
        }
    }
}

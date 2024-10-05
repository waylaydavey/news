<?php

namespace App\Http\Controllers;

use App\Exports\BulkPostExport;
use App\Http\Requests\CreateBulkPostRequest;
use App\Http\Requests\CreatePostRequest;
use App\Http\Requests\ImageUploadReuest;
use App\Http\Requests\OpenAIRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Imports\BulkPostImport;
use App\Models\Analytic;
use App\Models\Category;
use App\Models\Emoji;
use App\Models\Language;
use App\Models\Post;
use App\Models\PostReactionEmoji as PostReactionEmojiAlias;
use App\Models\PostVideo;
use App\Models\Setting;
use App\Models\SubCategory;
use App\Models\User;
use App\Repositories\PostRepository;
use App\Scopes\AuthoriseUserActivePostScope;
use App\Scopes\LanguageScope;
use App\Scopes\PostDraftScope;
use Illuminate\Support\Facades\Auth;
use Cocur\Slugify\Slugify;
use Cohensive\OEmbed\Facades\OEmbed;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Laracasts\Flash\Flash;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PostController extends AppBaseController
{
    /**
     * @var PostRepository
     */
    private $PostRepository;

    /**
     * CategoryRepository constructor.
     */
    public function __construct(PostRepository $PostRepository)
    {
        $this->PostRepository = $PostRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Application|Factory|View|Response
     */
    public function index(Request $request): \Illuminate\View\View
    {
        $subCategories = SubCategory::toBase()->get();
        $categories = Category::toBase()->get();

        return view('post.index', compact('subCategories', 'categories'));
    }

    /**
     * @return Application|RedirectResponse|Redirector
     */
    public function store(CreatePostRequest $request): RedirectResponse
    {
        $input = $request->all();

        if ($input['post_types'] == Post::ARTICLE_TYPE_ACTIVE && empty($input['image']) && empty($input['image'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.thumbnail_image_is_required')]);
        }
        if ($input['post_types'] == Post::OPEN_AI_ACTIVE && empty($input['image']) && empty($input['image'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.thumbnail_image_is_required')]);
        }
        if ($input['post_types'] == Post::GALLERY_TYPE_ACTIVE && empty($input['image']) && empty($input['image'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.thumbnail_image_is_required')]);
        }
        if ($input['post_types'] == Post::SORTED_TYPE_ACTIVE && empty($input['image']) && empty($input['image'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.thumbnail_image_is_required')]);
        }

        if ($input['post_types'] == Post::VIDEO_TYPE_ACTIVE && empty($input['thumbnailImage']) && empty($input['thumbnail_image_url'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.thumbnail_image_is_required')]);
        }

        if ($input['post_types'] == Post::VIDEO_TYPE_ACTIVE && (empty($input['video_url']) || empty($input['video_embed_code'])) && empty($input['uploadVideo'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.please_enter_video_url_or_upload_video')]);
        }

        if ($input['post_types'] == Post::VIDEO_TYPE_ACTIVE && !empty($input['video_url'] || !empty($input['video_embed_code'])) && !empty($input['uploadVideo'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.You_can_use_any_one_of_upload_video_or_video_URL_option')]);
        }

        if ($input['post_types'] == Post::AUDIO_TYPE_ACTIVE && isset($input['audios']) && !isset($input['image'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.thumbnail_image_is_required')]);
        }

        if ($input['post_types'] == Post::AUDIO_TYPE_ACTIVE && empty($input['audios'])) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.please_select_audio_file')]);
        }

        if (count(explode(' ', $request->keywords)) > 10) {
            return redirect::back()->withInput($input)->withErrors([__('messages.placeholder.keyword_should_be_of_maximum_10_words_only')]);
        }
        if ($request['scheduled_post'] == 1) {
            $request->validate(['scheduled_post_time' => 'required']);
        }
        $input = $request->all();
        $input['created_by'] = (!empty($input['created_by'])) ? $input['created_by'] : getLogInUserId();

        $this->PostRepository->store($input);

        Flash::success(__('messages.placeholder.post_created_successfully'));
        if (!Auth::user()->hasRole('customer')) {
            return redirect(route('posts.index'));
        }

        if (Auth::user()->hasRole('customer')) {
            return redirect(route('customer-posts.index'));
        }
    }
    
public function show($id)
{
    // Fetch the current post detail, including the article
    $postDetail = Post::with('postArticle')->findOrFail($id);

    // Fetch the next post based on the current post ID
    $nextPost = Post::where('id', '>', $postDetail->id)
        ->orderBy('id')
        ->first();

    // If there's no next post, you can decide to set it to null or to some default post.
    // For example, to get the first post:
    if (!$nextPost) {
        $nextPost = Post::orderBy('id')->first(); // Adjust as necessary for your logic
    }

    // Return the view with the post detail and next post
    return view('article', compact('postDetail', 'nextPost'));
}

    public function show($id): \Illuminate\View\View
    {
        $post = Post::whereId($id)->withoutGlobalScope(AuthoriseUserActivePostScope::class)
            ->withoutGlobalScope(LanguageScope::class)
            ->withoutGlobalScope(PostDraftScope::class)
            ->with('PostReaction', 'user', 'language', 'category', 'subCategory')->first();
        $countEmoji = PostReactionEmojiAlias::wherePostId($post->id)->get()->groupBy('emoji_id');
        $emojis = Emoji::whereStatus(Emoji::ACTIVE)->get();

        return view('post.show', compact('post', 'countEmoji', 'emojis'));
    }

    /**
     * @return Application|Factory|View
     */
    public function postFormat(Request $request): \Illuminate\View\View
    {
        $sectionName = ($request->get('section') === null) ? 'post_format' : $request->get('section');

        if ($request->get('section') != null) {
            if ($sectionName == Post::ARTICLE) {
                $sectionType = Post::ARTICLE_TYPE_ACTIVE;
            } elseif ($sectionName == Post::GALLERY) {
                $sectionType = Post::GALLERY_TYPE_ACTIVE;
            } elseif ($sectionName == Post::SORT_LIST) {
                $sectionType = Post::SORTED_TYPE_ACTIVE;
            } elseif ($sectionName == Post::TRIVIA_QUIZ) {
                $sectionType = Post::TRIVIA_TYPE_ACTIVE;
            } elseif ($sectionName == Post::PERSONALITY_QUIZ) {
                $sectionType = Post::PERSONALITY_TYPE_ACTIVE;
            } elseif ($sectionName == Post::VIDEO) {
                $sectionType = Post::VIDEO_TYPE_ACTIVE;
            } elseif ($sectionName == Post::AI) {
                $sectionType = Post::OPEN_AI_ACTIVE;
            } else {
                $sectionType = Post::AUDIO_TYPE_ACTIVE;
            }

            return view('post.post_table', compact('sectionName', 'sectionType'));
        }

        return view("post.$sectionName", compact('sectionName'));
    }

    /**
     * @return Application|Factory|View|RedirectResponse
     */
    public function postType(Request $request)
    {
        if ($request->get('section') === null) {
            return \redirect(route('posts.index'));
        }
        $sectionName = ($request->get('section') === null) ? 'article-create' : $request->get('section');
        $allStaff = User::where('type', User::STAFF)->pluck('first_name', 'id');

        if ($sectionName == Post::POST_FORMAT) {
            if (Auth::user()->hasRole('customer')) {
                return redirect()->route('customer.post_format');
            }

            return redirect()->route(Post::POST_FORMAT);
        } elseif ($sectionName == Post::GALLERY_CREATE) {
            $sectionType = Post::GALLERY_TYPE_ACTIVE;
            $sectionAdd = Post::ADD_GALLERY;
            $addRouteSection = Post::GALLERY;
        } elseif ($sectionName == Post::SORT_LIST_CREATE) {
            $sectionType = Post::SORTED_TYPE_ACTIVE;
            $sectionAdd = Post::ADD_SORT_LIST;
            $addRouteSection = Post::SORT_LIST;
        } elseif ($sectionName == Post::TRIVIA_QUIZ_CREATE) {
            $sectionType = Post::TRIVIA_TYPE_ACTIVE;
            $sectionAdd = Post::ADD_TRIVIA_QUIZE;
            $addRouteSection = Post::TRIVIA_QUIZ;
        } elseif ($sectionName == Post::PERSONALITY_QUIZ_CREATE) {
            $sectionType = Post::PERSONALITY_TYPE_ACTIVE;
            $sectionAdd = Post::ADD_PERSONALITY_QUIZ;
            $addRouteSection = Post::PERSONALITY_QUIZ;
        } elseif ($sectionName == Post::VIDEO_CREATE) {
            $sectionType = Post::VIDEO_TYPE_ACTIVE;
            $sectionAdd = Post::ADD_VIDEO;
            $addRouteSection = Post::VIDEO;
        } elseif ($sectionName == Post::AUDIO_CREATE) {
            $sectionType = Post::AUDIO_TYPE_ACTIVE;
            $sectionAdd = Post::ADD_AUDIO;
            $addRouteSection = Post::AUDIO;
        } elseif ($sectionName == Post::OPEN_AI_CREATE) {
            $sectionType = Post::OPEN_AI_ACTIVE;
            $sectionAdd = Post::ADD_AI;
            $addRouteSection = Post::AI;
        } else {
            $sectionType = Post::ARTICLE_TYPE_ACTIVE;
            $sectionAdd = Post::ADD_ARTICLE;
            $addRouteSection = Post::ARTICLE;
        }

        return view('post.create', compact('sectionName', 'sectionType', 'sectionAdd', 'addRouteSection', 'allStaff'));
    }

    public function edit($post): \Illuminate\View\View
    {
        $post = Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->with([
            'language', 'category', 'subCategory', 'postArticle', 'postAudios', 'postGalleries.media', 'postSortLists.media',
        ])->findOrFail($post);
        $sectionType = $post->post_types;
        $allStaff = User::where('type', User::STAFF)->pluck('first_name', 'id');

        return view('post.edit', compact('post', 'sectionType', 'allStaff'));
    }

    /**
     * Update the specified Staff in storage.
     *
     * @param  User  $staff
     * @return Application|RedirectResponse|Redirector
     */
    public function update(UpdatePostRequest $request): RedirectResponse
    {
        if ($request['scheduled_post'] == 1) {
            $request->validate(['scheduled_post_time' => 'required']);
        }
        $input = $request->all();
        $postVideo = PostVideo::wherePostId($input['id'])->first();

        if ($input['post_types'] == Post::VIDEO_TYPE_ACTIVE && !empty($input['video_url']) && !empty($input['uploadVideo'])) {
            return redirect::back()->withErrors([__('messages.placeholder.you_can_use_any_one_of_upload_video_or_video_URL_option')]);
        }

        if ($input['post_types'] == Post::VIDEO_TYPE_ACTIVE && (empty($input['video_url']) || empty($input['video_embed_code'])) && $postVideo->getMedia(PostVideo::VIDEO_PATH)->count() == 0 && empty($input['uploadVideo'])) {
            return redirect::back()->withErrors([__('messages.placeholder.please_enter_video_url_or_upload_a_video')]);
        }

        if ($input['post_types'] == Post::VIDEO_TYPE_ACTIVE && empty($input['thumbnailImage']) && empty($input['thumbnail_image_url']) && $postVideo->getMedia(PostVideo::THUMBNAIL_PATH)->count() == 0) {
            return redirect::back()->withErrors([__('messages.placeholder.thumbnail_image_is_required')]);
        }

        $input['created_by'] = (!empty($input['created_by'])) ? $input['created_by'] : getLogInUserId();
        $this->PostRepository->update($input, $input['id']);

        Flash::success(__('messages.placeholder.post_updated_successfully'));
        if (!Auth::user()->hasRole('customer')) {
            return redirect(route('posts.index'));
        }

        if (Auth::user()->hasRole('customer')) {
            return redirect(route('customer-posts.index'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($post): JsonResponse
    {
        Analytic::wherePostId($post)->delete();
        Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->find($post)->delete();

        return $this->sendSuccess(__('messages.placeholder.post_deleted_successfully'));
    }

    public function language(Request $request): JsonResponse
    {
        $category = Category::where('lang_id', $request->data)->pluck('id', 'name')->toArray();

        return $this->sendResponse($category, __('messages.placeholder.category_retrieved_successfully'));
    }

    public function category(Request $request): JsonResponse
    {
        $sub_category = SubCategory::where('parent_category_id', $request->cat_id)->where('lang_id', $request->lang_id)
            ->pluck('id', 'name');

        return $this->sendResponse($sub_category, __('messages.placeholder.sub_category_retrieved_successfully'));
    }

    public function categoryFilter(Request $request): JsonResponse
    {
        if ($request->cat_id == null) {
            $sub_category = SubCategory::all()->pluck('id', 'name');
        } else {
            $sub_category = SubCategory::where('parent_category_id', $request->cat_id)->pluck('id', 'name');
        }

        return $this->sendResponse($sub_category, __('messages.placeholder.sub_category_retrieved_successfully'));
    }

    /**
     * @return mixed
     */
    public function imgUpload(ImageUploadReuest $request)
    {

        $input = $request->all();
        $user = getLogInUser();

        $imageCheck = Media::where('collection_name', User::NEWS_IMAGE)->where('file_name', $input['image']->getClientOriginalName())->exists();
        if (!$imageCheck) {
            if ((!empty($input['image']))) {
                $media = $user->addMedia($input['image'])->toMediaCollection(User::NEWS_IMAGE);
            }
            $data['url'] = $media->getFullUrl();
            $data['mediaId'] = $media->id;

            return $this->sendResponse(['data' => $data], __('messages.placeholder.image_upload_successfully'));
        } else {
            return $this->sendError(__('messages.placeholder.already_image_exist'));
        }
    }

    /**
     * @return mixed
     */
    public function imageGet()
    {
        $images = getLogInUser()->getMedia(User::NEWS_IMAGE);
        $data = [];
        foreach ($images as $index => $image) {
            $data[$index]['imageUrls'] = $image->getFullUrl();
            $data[$index]['id'] = $image->id;
        }

        return $this->sendResponse($data, __('messages.placeholder.img_retrieved'));
    }

    /**
     * @return mixed
     */
    public function imageDelete($id)
    {
        $media = Media::whereId($id)->firstorFail();
        $media->delete();

        return $this->sendResponse($media, __('messages.placeholder.image_delete_successfully'));
    }

    public function getVideoByUrl(Request $request)
    {
        $url = $request->videoUrl;
        if ($url == null) {
            return $this->sendError(__('messages.placeholder.please_enter_video_URL'));
        }
        $embed = OEmbed::get($url);
        if (empty($embed)) {
            return $this->sendError(__('messages.placeholder.something_wrong_occurred_please_try_again'));
        }

        $embedData = [];
        $embedData['embed_url'] = $embed->src();
        $embedData['html'] = $embed->html(['width' => 280, 'height' => 250]);
        $embedData['thumbnail_url'] = $embed->thumbnail()['url'];

        return $this->sendResponse($embedData, __('messages.placeholder.data_retried'));
    }

    public function bulkPost(): \Illuminate\View\View
    {
        return view('bulk_post.index');
    }

    public function idsList()
    {
        $lang = Language::with('Categories.subCategories')->get();

        $html = view('bulk_post.ids-data', compact('lang'))->render();

        return $this->sendResponse($html, __('messages.placeholder.data_retried'));
    }

    public function documentation()
    {
        $html = view('bulk_post.documentation')->render();

        return $this->sendResponse($html, __('messages.placeholder.data_retried'));
    }

    public function export()
    {
        $users = [
            [
                'id' => 1,
                'name' => 'Hardik',
                'email' => 'hardik@gmail.com',
                'image' => 'https://infyom.com/static/f9cfd0f86a2dba59edab2dd31b9e3146/b4859/logo.webp',
                'lang_id' => 1,
                'category_id' => 1,
                'sub_category' => 1,
                'tag' => 'test',
                'visibility' => 1,
            ],
            [
                'id' => 2,
                'name' => 'Hardik',
                'email' => 'hardik@gmail.com',
                'image' => 'https://infyom.com/static/f9cfd0f86a2dba59edab2dd31b9e3146/b4859/logo.webp',
                'lang_id' => 1,
                'category_id' => 1,
                'sub_category' => 1,
                'tag' => 'test',
                'visibility' => 1,
            ],
            [
                'id' => 3,
                'name' => 'Hardik',
                'email' => 'hardik@gmail.com',
                'image' => 'https://infyom.com/static/f9cfd0f86a2dba59edab2dd31b9e3146/b4859/logo.webp',
                'lang_id' => 1,
                'category_id' => 1,
                'sub_category' => 1,
                'tag' => 'test',
                'visibility' => 1,
            ],
        ];

        return Excel::download(new BulkPostExport($users), 'csv_template.csv');
    }

    public function bulkPostStore(CreateBulkPostRequest $request): Redirector|Application|RedirectResponse
    {
        ini_set('max_execution_time', 36000000);
        $input = $request->all();

        $validation = Validator::make($input, [
            'bulk_post' => 'required',
        ]);
        $this->errors = $validation->messages();
        if (!$validation->passes()) {
            Flash::error(__('messages.placeholder.please_enter_CSV_files'));
        }
        if ($validation->passes()) {
            $import = new BulkPostImport();
            $excel = Excel::import($import, $request->file('bulk_post'), null, \Maatwebsite\Excel\Excel::CSV);
            $errors = $import->getErrors();
            if ($errors) {
                foreach ($errors as $key => $error) {
                    if ($error instanceof MessageBag) {
                        foreach ($error->all() as $message) {
                            Flash::error($message);
                        }
                    } else {
                        foreach ($error as $key => $value) {
                            Flash::error($value);
                        }
                    }
                }
            } else {
                Flash::success(__('messages.placeholder.bulk_post_created_successfully'));
            }
        }

        return redirect(route('bulk-post-index'));
    }

    public function openAi(OpenAIRequest $request)
    {
        $input = $request->all();
        $openAiKey = Setting::where('key', 'open_AI_key')->value('value');
        if (empty($openAiKey)) {
         $openAiKey = config('services.open_ai.open_ai_key');
     }
        $client = new \GuzzleHttp\Client();

        $data = \Illuminate\Support\Facades\Http::withToken($openAiKey)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $input['openAiModel'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $input['postTitle'],
                    ],
                ],
                'temperature' => (float) $input['Temperature'],
                'max_tokens' => (int) $input['MaximumLength'],
                'top_p' => (float) $input['InputTopPId'],
            ]);

        if (isset($data->json()['error'])) {
            return $this->sendError($data->json()['error']['message']);
        } else {
            $text = $data->json()['choices'][0]['message']['content'];

            return $this->sendResponse($text, __('messages.placeholder.content_generated_successfully'));
        }
    }

    public function slug(Request $request)
    {
        $text = $request->text;
        if ($text == '') {
            $text = '';
        }
//         $slugify = new Slugify();
//         $slug = $slugify->slugify($text);
        $slug = make_slug($text);

        return $this->sendResponse( $slug, __('messages.placeholder.content_generated_successfully'));
    }

    public function additionalMediadelete($id)
    {
        $mediaItem = Media::find($id);

        if($mediaItem){
            $mediaItem->delete();
        }

        return $this->sendSuccess(__('messages.post.additional').' '.__('messages.common.delete_message'));
    }

    public function updateVisibility(Request $request)
    {
        $postId = $request->id;
        $post = Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->findOrFail($postId);
            $postVisibilityCount = Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->whereCreatedBy(getLogInUserId())->whereVisibility(1)->count();

            if ($post->status == Post::STATUS_DRAFT) {
                    return response()->json(['status' => 'error', 'message' => 'This functionality not allowed in demo.']);
                    $message = __('messages.placeholder.given_post_is_not_yet_published');

                    return $this->sendError($message);
            } else {
                    if (Auth::user()->hasRole('customer')) {

                            if ($postVisibilityCount < getloginuserplan()->plan->post_count) {
                                        $post->update([
                                                'visibility' => !$post->visibility,
                                        ]);
                            } else {

                                        $post->update([
                                                'visibility' => 0,
                                        ]);

                                        $message = __('messages.placeholder.please_upgrade_plan');

                                        return $this->sendSuccess($message);
                            }
                    } else {
                            $post->update([
                                        'visibility' => !$post->visibility,
                            ]);

                            $message = $post->visibility ? __('messages.placeholder.post_added_to_visibility_successfully') : __('messages.placeholder.post_removed_from_visibility_successfully');
                            return $this->sendSuccess($message);
                    }
        }
    }

    public function updateHeadline(Request $request)
    {
        $postId = $request->id;
        $post = Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->findOrFail($postId);
        $post->update([
                 'show_on_headline' => !$post->show_on_headline,
        ]);

        $message = $post->show_on_headline ? __('messages.placeholder.post_added_on_headline_successfully') : __('messages.placeholder.post_removed_from_headline_successfully');
        return $this->sendSuccess($message);
    }
    public function updateFeatured(Request $request)
    {
        $postId = $request->id;
        $post = Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->findOrFail($postId);
        $post->update([
                 'featured' => !$post->featured,
        ]);

        $message = $post->featured ? __('messages.placeholder.post_added_to_featured_successfully') : __('messages.placeholder.post_removed_from_featured_successfully');
        return $this->sendSuccess($message);
    }
    public function updateBreaking(Request $request)
    {
        $postId = $request->id;
        $post = Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->findOrFail($postId);
        $post->update([
                 'breaking' => !$post->breaking,
        ]);

        $message = $post->breaking ? __('messages.placeholder.post_added_to_breaking_successfully') : __('messages.placeholder.post_removed_from_breaking_successfully');
        return $this->sendSuccess($message);
    }

    public function updateSlider(Request $request)
    {
        $postId = $request->id;
        $post = Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->findOrFail($postId);
        $post->update([
                'slider' => !$post->slider,
        ]);

        $message = $post->slider ? __('messages.placeholder.post_added_to_slider_successfully') : __('messages.placeholder.post_removed_from_slider_successfully');
        return $this->sendSuccess($message);
    }

    public function updateRecommended(Request $request)
    {
        $postId = $request->id;
        $post = Post::withoutGlobalScope(LanguageScope::class)->withoutGlobalScope(PostDraftScope::class)->findOrFail($postId);
        $post->update([
                'recommended' => !$post->recommended,
        ]);

        $message = $post->recommended ? __('messages.placeholder.post_added_to_recommended_successfully') : __('messages.placeholder.post_removed_from_recommended_successfully');
        return $this->sendSuccess($message);
    }
}

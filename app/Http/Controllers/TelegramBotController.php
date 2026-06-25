<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class TelegramBotController extends Controller
{
    /**
     * Handle incoming webhooks from the Storefront Telegram Bot
     */
    public function handle(Request $request)
    {
        try {
            $update = $request->all();

            Log::info('Storefront Telegram webhook received', ['update' => $update]);

            if (isset($update['message'])) {
                $message = $update['message'];
                $chatId = $message['chat']['id'];
                $text = trim($message['text'] ?? '');

                if (empty($text)) {
                    return response()->json(['ok' => true]);
                }

                $botToken = env('TELEGRAM_BOT_TOKEN');
                $geminiKey = env('GEMINI_API_KEY');

                if (empty($botToken) || empty($geminiKey)) {
                    Log::error('TelegramBotController: TELEGRAM_BOT_TOKEN or GEMINI_API_KEY not configured in .env');
                    return response()->json(['ok' => true]);
                }

                // Keyboard with WebApp link
                $keyboard = [
                    'keyboard' => [
                        [
                            [
                                'text' => '🛍️ تصفح المتجر',
                                'web_app' => ['url' => 'https://paranakids.com']
                            ]
                        ]
                    ],
                    'resize_keyboard' => true,
                    'persistent' => true
                ];

                // 1. Handle /start command
                if ($text === '/start') {
                    $welcome = "هلا بيك عيوني بـ **بارانا كيدز**! 🧸✨\n\nتگدر تتصفح المتجر وتشوف الملابس والقياسات المتوفرة مباشرة من زر فتح المتجر بالأسفل 👇";
                    $this->sendMessage($chatId, $welcome, $keyboard);
                    return response()->json(['ok' => true]);
                }

                // Show typing status indicator
                $this->sendChatAction($chatId, 'typing');

                // 2. Fetch product catalog context from backend API (Cached for 3 minutes)
                $catalogContext = $this->getProductCatalogContext();

                // 3. Retrieve conversation history (limit to last 10 messages)
                $cacheKey = "tg_chat_history_{$chatId}";
                $history = Cache::get($cacheKey, []);

                // Append user message
                $history[] = [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $text]
                    ]
                ];

                // 4. Construct Gemini instructions
                $systemInstruction = "أنت مساعد مبيعات ذكي ومؤدب لبوت Parana Kids (متجر ملابس وأشياء الأطفال).\n" .
                                     "يجب عليك الالتزام بالقواعد التالية بشكل صارم جداً:\n\n" .
                                     "1. اللهجة واللغة:\n" .
                                     "- أجب فقط باللغة العربية وباللهجة العراقية اللطيفة والمحببة (مثال: \"هلا بيك عيني\"، \"شلون أگدر أساعدك اليوم؟\"، \"تدلل عيوني\"، \"صار من عيوني\"، \"فدوة لعينك\").\n" .
                                     "- لا تتحدث بالفصحى ولا بأي لغة أو لهجة أخرى غير العراقية الدارجة.\n\n" .
                                     "2. نطاق الإجابة المسموح به:\n" .
                                     "- يُسمح لك فقط بالإجابة عن المنتجات المتوفرة في المتجر، قياساتها، أسعارها، والأقسام المتوفرة لدينا.\n" .
                                     "- لا تجب على أي سؤال عام أو خارج موضوع المتجر (مثل الأسئلة العلمية، الرياضية، التاريخية، الترجمة، البرمجة، أو أي دردشة عامة).\n" .
                                     "- إذا سألك المستخدم عن أي شيء خارج المتجر أو خارج المنتجات المتوفرة، اعتذر منه بلطف شديد باللهجة العراقية وأخبره أنك متخصص فقط بمساعدته في منتجات Parana Kids وعرض المتوفر منها.\n\n" .
                                     "3. فهم سياق المنتجات المرفق:\n" .
                                     "- سياق المنتجات يأتي بصيغة مضغوطة مفصولة برمز البايب '|' كالتالي:\n" .
                                     "  `كود|اسم|سعر|قسم|قياسات(كمية)|رابط_الصورة`\n" .
                                     "- اعرض الأسعار بالدينار العراقي (مثلاً: 25,000 دينار عراقي).\n\n" .
                                     "4. إرسال صور المنتجات وتوجيه المستخدم:\n" .
                                     "- إذا طلب المستخدم صورة لمنتج معين (مثال: \"أريد صورته\"، \"شلون شكله\"، \"دزلي صورته\")، وكان رابط الصورة متوفراً، اكتب له رداً لطيفاً مع إدراج رابط الصورة بالصيغة التالية تماماً في نهاية الرد: `[IMAGE: رابط_الصورة]`.\n" .
                                     "- لا تخترع روابط صور من عندك أبداً؛ استخدم فقط الروابط المتاحة.\n" .
                                     "- عند الحاجة، تگدر توجه الزبون يفتح الموقع paranakids.com مباشرة للتسوق والدفع.";

                if (!empty($catalogContext)) {
                    $systemInstruction .= "\n\nسياق المنتجات الحالي في المتجر:\n" . $catalogContext;
                }

                // 5. Call Gemini API
                $requestPayload = [
                    'contents' => $history,
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => $systemInstruction]
                        ]
                    ]
                ];

                $response = Http::timeout(15)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$geminiKey}",
                    $requestPayload
                );

                if ($response->failed()) {
                    Log::error('Gemini API request failed', ['status' => $response->status(), 'body' => $response->body()]);
                    $this->sendMessage($chatId, 'صار عندي خلل بسيط بالاتصال، يرجى المحاولة مرة ثانية عيني.', $keyboard);
                    return response()->json(['ok' => true]);
                }

                $result = $response->json();
                $aiText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                if (empty($aiText)) {
                    $this->sendMessage($chatId, 'ما گدرت أفهم الرسالة بشكل صحيح، تكدر تعيدها عيني؟', $keyboard);
                    return response()->json(['ok' => true]);
                }

                // Append response to history and save
                $history[] = [
                    'role' => 'model',
                    'parts' => [
                        ['text' => $aiText]
                    ]
                ];

                if (count($history) > 10) {
                    $history = array_slice($history, -10);
                }
                Cache::put($cacheKey, $history, now()->addMinutes(30));

                // Process image tags
                $imageUrl = null;
                if (preg_match('/\[IMAGE:\s*([^\]]+)\]/i', $aiText, $matches)) {
                    $potentialUrl = trim($matches[1]);
                    if (filter_var($potentialUrl, FILTER_VALIDATE_URL) && strpos($potentialUrl, 'لا يوجد') === false) {
                        $imageUrl = $potentialUrl;
                    }
                    $aiText = trim(str_replace($matches[0], '', $aiText));
                }

                // Send reply
                if ($imageUrl) {
                    $this->sendPhoto($chatId, $imageUrl, $aiText ?: 'تفضل عيوني، هاي صورة المنتج:', $keyboard);
                } else {
                    $this->sendMessage($chatId, $aiText, $keyboard);
                }
            }

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Storefront Telegram webhook exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['ok' => true]); // Send ok to prevent Telegram retries
        }
    }

    /**
     * Send text message helper
     */
    private function sendMessage($chatId, $text, $keyboard = null)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", $params);
            if (!$response->successful()) {
                // Try fallback without markdown in case of parsing errors
                unset($params['parse_mode']);
                Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", $params);
            }
            return true;
        } catch (\Exception $e) {
            Log::error('TelegramBotController sendMessage exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send photo helper
     */
    private function sendPhoto($chatId, $photoUrl, $caption = '', $keyboard = null)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $params = [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'parse_mode' => 'Markdown',
        ];

        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendPhoto", $params);
            if (!$response->successful()) {
                // Try fallback without markdown
                unset($params['parse_mode']);
                Http::post("https://api.telegram.org/bot{$botToken}/sendPhoto", $params);
            }
            return true;
        } catch (\Exception $e) {
            Log::error('TelegramBotController sendPhoto exception: ' . $e->getMessage());
            // Fallback to text message
            return $this->sendMessage($chatId, $caption . "\n\n(رابط الصورة: {$photoUrl})", $keyboard);
        }
    }

    /**
     * Send chat action indicator
     */
    private function sendChatAction($chatId, $action)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        try {
            Http::post("https://api.telegram.org/bot{$botToken}/sendChatAction", [
                'chat_id' => $chatId,
                'action' => $action,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get store products catalog context from backend API (cached)
     */
    private function getProductCatalogContext()
    {
        return Cache::remember('store_catalog_context', 180, function () {
            try {
                $response = Http::timeout(10)->get('https://parana-kids-main-sbv4op.laravel.cloud/api/customer/products', [
                    'per_page' => 100
                ]);

                if ($response->successful()) {
                    $resData = $response->json();
                    $products = $resData['data'] ?? [];

                    if (empty($products)) {
                        return "لا توجد منتجات متوفرة حالياً في المتجر.";
                    }

                    $context = "كود|اسم|سعر|قسم|قياسات(كمية)|رابط_الصورة\n";
                    foreach ($products as $product) {
                        $code = $product['code'] ?? 'N/A';
                        $name = $product['name'] ?? '';
                        $price = round($product['effective_price'] ?? $product['selling_price'] ?? 0);
                        $department = $product['warehouse_name'] ?? 'غير محدد';
                        
                        // Format sizes
                        $sizesList = [];
                        $sizes = $product['sizes'] ?? [];
                        foreach ($sizes as $size) {
                            $qty = $size['quantity'] ?? 0;
                            if ($qty > 0) {
                                $sizesList[] = "{$size['size_name']}({$qty})";
                            }
                        }
                        $sizesStr = empty($sizesList) ? 'نفدت' : implode(',', $sizesList);
                        $imageUrl = $product['primary_image'] ?? 'لا يوجد';
                        
                        $context .= "{$code}|{$name}|{$price}|{$department}|{$sizesStr}|{$imageUrl}\n";
                    }
                    return trim($context);
                }
            } catch (\Exception $e) {
                Log::error('Error fetching catalog in TelegramBotController: ' . $e->getMessage());
            }
            return "";
        });
    }
}

<?php

namespace App\Jobs;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Chatbot\StatefulChatbotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInitialUserMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $conversation;
    protected $message;
    protected $respondWithAudio;

    /**
     * Create a new job instance.
     * --- ESTE É O CONSTRUTOR CORRIGIDO ---
     * @param WhatsAppConversation $conversation
     * @param WhatsAppMessage $message
     * @param boolean $respondWithAudio
     */
    public function __construct(WhatsAppConversation $conversation, WhatsAppMessage $message, bool $respondWithAudio)
    {
        $this->conversation = $conversation;
        $this->message = $message;
        $this->respondWithAudio = $respondWithAudio;
    }

    /**
     * Execute the job.
     *
     * @param StatefulChatbotService $chatbotService
     * @return void
     */
    public function handle(StatefulChatbotService $chatbotService): void
    {
        Log::info('Executing delayed job for initial message.', [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id
        ]);
        
        // Chama o método público no serviço para processar a mensagem após o delay
        $chatbotService->processQueuedMessage($this->conversation, $this->message, $this->respondWithAudio);
    }
}
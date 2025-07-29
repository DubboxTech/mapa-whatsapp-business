<?php

namespace App\Services\Chatbot;

use App\Models\WhatsAppConversation;
use App\Services\AI\GeminiAIService;
use Illuminate\Support\Facades\Log;

class StatefulChatbotService
{
    protected GeminiAIService $geminiAiService;

    public function __construct(GeminiAIService $geminiAiService)
    {
        $this->geminiAiService = $geminiAiService;
    }

    /**
     * Ponto de entrada principal para lidar com uma nova mensagem do usuário.
     * Analisa a intenção e orquestra a resposta ou a ação apropriada.
     *
     * @param WhatsAppConversation $conversation
     * @param string $userMessage
     * @return array Ação a ser tomada (ex: 'reply', 'escalate') e a resposta.
     */
    public function handle(WhatsAppConversation $conversation, string $userMessage): array
    {
        // 1. Analisa a mensagem do usuário para extrair a intenção e outras metadados.
        $analysis = $this->geminiAiService->analyzeUserMessage($conversation, $userMessage);

        // Fallback: Se a análise falhar, transfere para um humano por segurança.
        if (!$analysis) {
            Log::warning('Falha na análise da mensagem pela IA. Escalando para humano.', ['conversation_id' => $conversation->id]);
            return $this->escalateToHuman($conversation, 'Desculpe, não consegui processar sua mensagem. Vou transferir para um de nossos especialistas.');
        }

        // Atualiza o estado da conversa com a última intenção detectada.
        $conversation->update(['chatbot_state' => $analysis['intent']]);

        // 2. Decide a próxima ação com base na intenção detectada.
        switch ($analysis['intent']) {
            // Casos que DEVEM ser escalados para um atendente humano.
            case 'transferir_atendente':
                return $this->escalateToHuman($conversation, 'Claro. Um momento enquanto eu transfiro você para um especialista.');

            case 'denuncia_irregularidade':
                return $this->escalateToHuman($conversation, 'Entendido. Para garantir que sua denúncia seja registrada corretamente e com a devida atenção, estou transferindo você para um atendente especializado. Por favor, aguarde.');

            // Casos que são tratados pela IA para gerar uma resposta textual.
            case 'info_plano_safra':
            case 'info_gta':
            case 'info_car':
            case 'info_renagro':
            case 'info_sanidade_animal':
            case 'info_sanidade_vegetal':
            case 'info_certificacao':
            case 'consulta_registro_agrotoxico':
            case 'informacoes_gerais':
            case 'saudacao_despedida':
            case 'nao_entendido':
            default:
                return $this->generateAiResponse($conversation, $userMessage);
        }
    }

    /**
     * Gera uma resposta de texto usando a IA.
     *
     * @param WhatsAppConversation $conversation
     * @param string $userMessage
     * @return array
     */
    private function generateAiResponse(WhatsAppConversation $conversation, string $userMessage): array
    {
        $responsePayload = $this->geminiAiService->processMessage($conversation, $userMessage);

        if ($responsePayload && !empty($responsePayload['response'])) {
            return [
                'action' => 'reply',
                'response' => $responsePayload['response'],
            ];
        }
        
        // Fallback se a geração de texto falhar.
        Log::warning('Falha na geração de resposta pela IA. Escalando para humano.', ['conversation_id' => $conversation->id]);
        return $this->escalateToHuman($conversation, 'Não consegui encontrar a informação que você precisa no momento. Vou te transferir para um especialista.');
    }

    /**
     * Prepara a ação de escalonamento para um atendente humano.
     *
     * @param WhatsAppConversation $conversation
     * @param string $messageToUser A mensagem a ser enviada ao usuário antes da transferência.
     * @return array
     */
    private function escalateToHuman(WhatsAppConversation $conversation, string $messageToUser): array
    {
        // Atualiza o status da conversa para indicar que um humano deve assumir.
        $conversation->update([
            'status' => 'human_takeover',
            'chatbot_state' => 'escalated_to_agent'
        ]);

        Log::info('Conversa escalada para atendimento humano.', ['conversation_id' => $conversation->id]);

        return [
            'action' => 'escalate',
            'response' => $messageToUser,
        ];
    }
}
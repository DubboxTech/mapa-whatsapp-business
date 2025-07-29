<?php

namespace App\Services\AI;

use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class GeminiAIService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    // Alterado de 'sedes' para 'mapa'
    private ?string $mapaKnowledgeBase = null;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key');
        $this->loadKnowledgeBase();
    }

    private function loadKnowledgeBase(): void
    {
        try {
            // Alterado para carregar a base de conhecimento do MAPA
            if (Storage::disk('local')->exists('mapa.json')) {
                $this->mapaKnowledgeBase = Storage::disk('local')->get('mapa.json');
            }
        } catch (Exception $e) {
            Log::error('Falha ao carregar mapa.json.', ['error' => $e->getMessage()]);
        }
    }

    public function analyzeUserMessage(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context = $this->buildConversationContext($conversation);
        // O prompt de análise foi completamente refatorado para o MAPA
        $prompt = $this->buildAnalysisPrompt($userMessage, $context, $conversation->chatbot_state);
        $rawResponse = $this->sendRequestToGemini($prompt);

        if (!$rawResponse || empty($rawResponse['response'])) {
            return null;
        }

        $jsonString = $this->extractJsonFromString($rawResponse['response']);
        $analysis = json_decode($jsonString, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($analysis)) {
            Log::info('Análise do Gemini (MAPA) recebida.', ['analysis' => $analysis]);
            return $analysis;
        }

        Log::warning('Falha ao decodificar JSON da análise (MAPA).', ['raw_response' => $rawResponse['response']]);
        return null;
    }

    public function processMessage(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context = $this->buildConversationContext($conversation);
        // O prompt de resposta textual foi completamente refatorado para o MAPA
        $prompt = $this->buildTextResponsePrompt($userMessage, $context);
        return $this->sendRequestToGemini($prompt, 0.7);
    }

    private function sendRequestToGemini(string $promptContents, float $temperature = 0.2, int $maxTokens = 2048): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('Chave da API do Gemini não configurada.');
            return null;
        }

        // Modelo pode ser ajustado conforme a necessidade, mantendo o da versão original
        $model = 'gemini-1.5-flash-latest';
        $payload = [
            'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens],
            'contents' => [['parts' => [['text' => $promptContents]]]],
        ];

        try {
            $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";
            $response = Http::withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if ($response->successful() && isset($response->json()['candidates'][0]['content']['parts'][0]['text'])) {
                return ['success' => true, 'response' => trim($response->json()['candidates'][0]['content']['parts'][0]['text'])];
            }
            Log::error('Erro na API do Gemini.', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        } catch (Exception $e) {
            Log::error('Exceção ao chamar Gemini.', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildConversationContext(WhatsAppConversation $conversation): string
    {
        $messages = $conversation->messages()->orderBy('created_at', 'asc')->get();
        if ($messages->isEmpty()) return 'Nenhum histórico de conversa anterior.';
        $context = "Histórico da conversa:\n";
        foreach ($messages as $msg) {
            $author = $msg->direction === 'inbound' ? 'Produtor' : 'Assistente MAPA'; // Ajuste de terminologia
            $content = $msg->content ?? "[Mídia: {$msg->type}]";
            $context .= "{$author}: {$content}\n";
        }
        return $context;
    }

    // <<< GRANDE REATORAÇÃO DO PROMPT DE ANÁLISE >>>
    private function buildAnalysisPrompt(string $userMessage, string $context, ?string $state): string
    {
        $stateDescription = $state ? "O estado atual da conversa é '{$state}'." : 'A conversa não possui estado específico.';
        $jsonWrapper = "Responda APENAS com o JSON solicitado, sem texto ou explicações adicionais.\n\n";

        return $jsonWrapper . <<<PROMPT
Você é um sistema de classificação para o chatbot do **MAPA (Ministério da Agricultura e Pecuária)**.
Seu objetivo é identificar a intenção do usuário sobre políticas agrícolas, financiamento, sanidade animal/vegetal e outros serviços do ministério.

Devolva um JSON com a seguinte estrutura:
{
  "is_off_topic": boolean,
  "contains_pii": boolean,
  "pii_type": "cpf" | "cnpj" | "outro" | null,
  "cep_detected": string | null,
  "intent": "info_plano_safra" | "info_gta" | "info_car" | "info_renagro" | "info_sanidade_animal" | "info_sanidade_vegetal" | "info_certificacao" | "denuncia_irregularidade" | "consulta_registro_agrotoxico" | "informacoes_gerais" | "transferir_atendente" | "saudacao_despedida" | "nao_entendido",
  "cultura_ou_praga_identificada": string | null
}

# Diretrizes de Mapeamento de Intenção:
- **info_plano_safra**: Perguntas sobre financiamento, crédito rural, Pronaf, Pronamp, Plano Safra.
- **info_gta**: Dúvidas sobre a Guia de Trânsito Animal, como emitir, validade, etc.
- **info_car**: Questões relacionadas ao Cadastro Ambiental Rural.
- **info_renagro**: Informações sobre o Registro Nacional de Tratores e Máquinas Agrícolas.
- **info_sanidade_animal**: Dúvidas sobre vacinação (febre aftosa, brucelose), doenças, programas sanitários para animais.
- **info_sanidade_vegetal**: Perguntas sobre pragas (ferrugem asiática), defensivos agrícolas, vazio sanitário. Se uma praga ou cultura for mencionada, preencha o campo `cultura_ou_praga_identificada`.
- **info_certificacao**: Questões sobre selos de qualidade, certificação de produtos orgânicos, Selo Arte, etc.
- **denuncia_irregularidade**: Quando o usuário quer denunciar uma prática ilegal, um produto irregular ou uma suspeita de doença/praga. **Sempre requer transferência para atendente**.
- **consulta_registro_agrotoxico**: Usuário pergunta se um determinado agrotóxico é registrado ou permitido.
- **transferir_atendente**: O usuário pede explicitamente para falar com um humano/especialista.
- **saudacao_despedida**: Cumprimentos (olá, bom dia) ou despedidas (obrigado, tchau).
- **informacoes_gerais**: Perguntas genéricas sobre o que o MAPA faz, endereço de unidades, etc., que não se encaixam nas outras intenções.
- **nao_entendido**: A mensagem não pôde ser compreendida ou não tem relação com as atribuições do MAPA.

# Outras Diretrizes:
1. {$stateDescription}
2. Se a intenção for `denuncia_irregularidade` ou o usuário pedir para falar com um atendente, a ação subsequente deve ser sempre transferir.
3. CPF/CNPJ são considerados PII (Informações de Identificação Pessoal). CEP NÃO é PII.
4. Se encontrar um CEP (formato de 8 dígitos), extraia-o para "cep_detected".
5. Se a intenção for `info_sanidade_vegetal` e a mensagem citar 'soja', 'milho', 'ferrugem', 'bicudo', preencha `cultura_ou_praga_identificada`.

Contexto da conversa:
{$context}

Mensagem do usuário para analisar: "{$userMessage}"
PROMPT;
    }

    // <<< GRANDE REATORAÇÃO DO PROMPT DE RESPOSTA >>>
    private function buildTextResponsePrompt(string $userMessage, string $context): string
    {
        return <<<PROMPT
Você é o **Assistente Virtual do MAPA (Ministério da Agricultura e Pecuária)**. Sua função é fornecer informações oficiais e precisas para produtores rurais, técnicos e cidadãos sobre os programas e serviços do agronegócio brasileiro.

--- BASE DE CONHECIMENTO (MAPA.JSON) ---
{$this->mapaKnowledgeBase}
--- FIM DA BASE ---

# Regras Essenciais
1. Seja sempre formal, técnico e prestativo. Use uma linguagem clara e direta. Evite emojis.
2. NUNCA invente informações, regulamentos ou datas. Se a resposta não estiver na base de conhecimento, informe que não possui detalhes sobre aquele tópico específico no momento e que, para informações detalhadas, o usuário deve consultar o site oficial do MAPA ou ser transferido para um especialista.
3. Não solicite dados pessoais sensíveis como CPF, CNPJ ou Inscrição Estadual.
4. Responda apenas com o texto solicitado, sem incluir formatação de código ou JSON.

# Histórico da Conversa
{$context}

# Pergunta do Produtor/Cidadão
{$userMessage}
PROMPT;
    }

    private function extractJsonFromString(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        return ($start !== false && $end !== false) ? substr($text, $start, $end - $start + 1) : null;
    }
}
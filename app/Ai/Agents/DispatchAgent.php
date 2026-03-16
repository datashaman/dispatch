<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(25)]
class DispatchAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @param  list<Tool>  $agentTools
     * @param  list<Message>  $conversationMessages
     */
    public function __construct(
        protected string $systemPrompt,
        protected array $agentTools = [],
        protected array $conversationMessages = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->systemPrompt;
    }

    public function tools(): iterable
    {
        return $this->agentTools;
    }

    /**
     * @return list<Message>
     */
    public function messages(): iterable
    {
        return $this->conversationMessages;
    }

    /**
     * Set conversation messages from prior history.
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    public function withConversationHistory(array $history): static
    {
        $this->conversationMessages = array_map(
            fn (array $message) => $message['role'] === 'assistant'
                ? new AssistantMessage($message['content'])
                : new Message('user', $message['content']),
            $history,
        );

        return $this;
    }
}

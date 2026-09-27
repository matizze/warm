<?php

namespace App\Mcp;

use App\Actions\Contacts\GetContact;
use App\Actions\Contacts\SearchContacts;
use App\Actions\Conversations\SearchConversations;
use App\Actions\Groups\GetGroup;
use App\Actions\Groups\SearchGroups;
use App\Actions\Media\DownloadMedia;
use App\Actions\Media\UploadMedia;
use App\Actions\Messages\SearchMessages;
use App\Actions\Messages\SendMessage;
use App\Enums\SearchMode;

/**
 * Exposes WARM capabilities to AI agents. Tools map to high-level actions,
 * never to raw table CRUD.
 */
final class ToolRegistry
{
    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'message.send',
                'description' => 'Envia uma mensagem (texto ou mídia já armazenada) por uma conversa.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'conversationId' => ['type' => 'string', 'description' => 'ID da conversa de destino'],
                        'type' => ['type' => 'string', 'enum' => ['text', 'image', 'audio', 'video', 'document'], 'default' => 'text'],
                        'content' => ['type' => 'string', 'description' => 'Texto ou legenda'],
                        'mediaId' => ['type' => 'string', 'description' => 'ID de mídia previamente enviada (media.upload)'],
                    ],
                    'required' => ['conversationId'],
                ],
            ],
            [
                'name' => 'contact.search',
                'description' => 'Pesquisa contatos por nome, telefone ou termo livre.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'phoneNumber' => ['type' => 'string'],
                        'search' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'contact.get',
                'description' => 'Consulta o contexto de um contato e suas conversas.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['contactId' => ['type' => 'string']],
                    'required' => ['contactId'],
                ],
            ],
            [
                'name' => 'group.search',
                'description' => 'Pesquisa grupos por nome ou termo.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'search' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'group.get',
                'description' => 'Consulta o contexto de um grupo (nome, descrição, observações).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['groupId' => ['type' => 'string']],
                    'required' => ['groupId'],
                ],
            ],
            [
                'name' => 'message.search',
                'description' => 'Pesquisa mensagens nos modos lexical, semântico ou híbrido.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'conversationId' => ['type' => 'string'],
                        'contactId' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']],
                        'search' => ['type' => 'string'],
                        'mode' => ['type' => 'string', 'enum' => ['lexical', 'semantic', 'hybrid'], 'default' => 'hybrid'],
                        'minScore' => ['type' => 'number'],
                        'limit' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'conversation.search',
                'description' => 'Pesquisa conversas por nome de contato/grupo ou termo.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'search' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'media.download',
                'description' => 'Baixa a mídia original de uma mensagem, armazena e processa.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['messageId' => ['type' => 'string']],
                    'required' => ['messageId'],
                ],
            ],
            [
                'name' => 'media.upload',
                'description' => 'Adiciona uma mídia ao sistema (base64) para uso posterior em message.send.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'base64' => ['type' => 'string'],
                        'filename' => ['type' => 'string'],
                        'mimeType' => ['type' => 'string'],
                        'messageId' => ['type' => 'string'],
                    ],
                    'required' => ['type', 'base64', 'filename'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments): array
    {
        return match ($name) {
            'message.send' => SendMessage::run($arguments),
            'contact.search' => SearchContacts::run($arguments),
            'contact.get' => GetContact::run((string) $arguments['contactId']),
            'group.search' => SearchGroups::run($arguments),
            'group.get' => GetGroup::run((string) $arguments['groupId']),
            'message.search' => SearchMessages::run(
                $arguments,
                SearchMode::tryFrom((string) ($arguments['mode'] ?? 'hybrid')) ?? SearchMode::Hybrid,
            ),
            'conversation.search' => SearchConversations::run($arguments),
            'media.download' => DownloadMedia::run((string) $arguments['messageId']),
            'media.upload' => UploadMedia::run($arguments),
            default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };
    }
}

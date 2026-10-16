<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\DeleteBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\ListBoardFolders;
use App\Mcp\Tools\Boards\ListBoards;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Mcp\Tools\BrowserChecks\CloseBrowserPage;
use App\Mcp\Tools\BrowserChecks\DoBrowserStep;
use App\Mcp\Tools\BrowserChecks\EditBrowserCheck;
use App\Mcp\Tools\BrowserChecks\GetBrowserRun;
use App\Mcp\Tools\BrowserChecks\GetBrowserSuite;
use App\Mcp\Tools\BrowserChecks\ListBrowserSuites;
use App\Mcp\Tools\BrowserChecks\LookAtPage;
use App\Mcp\Tools\BrowserChecks\OpenBrowserPage;
use App\Mcp\Tools\BrowserChecks\RunBrowserSuite;
use App\Mcp\Tools\BrowserChecks\SaveBrowserCheck;
use App\Mcp\Tools\BrowserChecks\SaveBrowserSuite;
use App\Mcp\Tools\BrowserChecks\StopBrowserRun;
use App\Mcp\Tools\Chats\GetChat;
use App\Mcp\Tools\Chats\ListChats;
use App\Mcp\Tools\Chats\SayInChat;
use App\Mcp\Tools\Chats\UpdateChat;
use App\Mcp\Tools\Drive\DeleteFile;
use App\Mcp\Tools\Drive\GetFile;
use App\Mcp\Tools\Drive\ListDrive;
use App\Mcp\Tools\Drive\RequestUpload;
use App\Mcp\Tools\Drive\UpdateFile;
use App\Mcp\Tools\Drive\UploadFile;
use App\Mcp\Tools\Notes\CreateNote;
use App\Mcp\Tools\Notes\DeleteNote;
use App\Mcp\Tools\Notes\EditNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\ListFolders;
use App\Mcp\Tools\Notes\ListNotes;
use App\Mcp\Tools\Notes\UpdateNote;
use App\Mcp\Tools\Places\DeletePlace;
use App\Mcp\Tools\Places\ListPlaces;
use App\Mcp\Tools\Places\SavePlace;
use App\Mcp\Tools\Places\SearchPlaces;
use App\Mcp\Tools\Product\GetProduct;
use App\Mcp\Tools\Product\GetProductPart;
use App\Mcp\Tools\Product\ListProductRequests;
use App\Mcp\Tools\Product\ListRequirements;
use App\Mcp\Tools\Product\SaveProductRequest;
use App\Mcp\Tools\Product\SaveRequirement;
use App\Mcp\Tools\Product\UpdateProduct;
use App\Mcp\Tools\Projects\CreateProject;
use App\Mcp\Tools\Projects\ListProjects;
use App\Mcp\Tools\Tables\CreateTable;
use App\Mcp\Tools\Tables\DeleteTable;
use App\Mcp\Tools\Tables\GetTable;
use App\Mcp\Tools\Tables\ListTableFolders;
use App\Mcp\Tools\Tables\ListTables;
use App\Mcp\Tools\Tables\UpdateTable;
use App\Mcp\Tools\Tickets\CreateTicket;
use App\Mcp\Tools\Tickets\GetTicket;
use App\Mcp\Tools\Tickets\ListTickets;
use App\Mcp\Tools\Tickets\UpdateTicket;
use App\Mcp\Tools\Trips\CreateTrip;
use App\Mcp\Tools\Trips\DeleteTrip;
use App\Mcp\Tools\Trips\GetTrip;
use App\Mcp\Tools\Trips\ListTripFolders;
use App\Mcp\Tools\Trips\ListTrips;
use App\Mcp\Tools\Trips\UpdateTrip;
use App\Mcp\Tools\UserTool;
use App\Mcp\Tools\Workflows\CreateWorkflow;
use App\Mcp\Tools\Workflows\DeleteWorkflow;
use App\Mcp\Tools\Workflows\GetWorkflow;
use App\Mcp\Tools\Workflows\GetWorkflowRun;
use App\Mcp\Tools\Workflows\ListWorkflowFolders;
use App\Mcp\Tools\Workflows\ListWorkflowRuns;
use App\Mcp\Tools\Workflows\ListWorkflows;
use App\Mcp\Tools\Workflows\ListWorkflowSteps;
use App\Mcp\Tools\Workflows\PublishWorkflow;
use App\Mcp\Tools\Workflows\RunWorkflow;
use App\Mcp\Tools\Workflows\SwitchWorkflow;
use App\Mcp\Tools\Workflows\UpdateWorkflow;

/**
 * Every tool both MCP servers offer, in the order a client lists them: the
 * personal server's acting as the token's owner, the admin server's as any
 * user given by user_id (`global`). A new tool goes here once.
 */
final class Toolset
{
    /** @var list<class-string<UserTool>> */
    private const TOOLS = [
        ListProjects::class,
        CreateProject::class,
        ListTickets::class,
        GetTicket::class,
        CreateTicket::class,
        UpdateTicket::class,
        GetProduct::class,
        GetProductPart::class,
        UpdateProduct::class,
        ListProductRequests::class,
        SaveProductRequest::class,
        ListRequirements::class,
        SaveRequirement::class,
        ListChats::class,
        GetChat::class,
        SayInChat::class,
        UpdateChat::class,
        ListNotes::class,
        ListFolders::class,
        GetNote::class,
        CreateNote::class,
        UpdateNote::class,
        EditNote::class,
        DeleteNote::class,
        ListBoards::class,
        ListBoardFolders::class,
        GetBoard::class,
        CreateBoard::class,
        UpdateBoard::class,
        DeleteBoard::class,
        ListTables::class,
        ListTableFolders::class,
        GetTable::class,
        CreateTable::class,
        UpdateTable::class,
        DeleteTable::class,
        ListTrips::class,
        ListTripFolders::class,
        GetTrip::class,
        CreateTrip::class,
        UpdateTrip::class,
        DeleteTrip::class,
        ListBrowserSuites::class,
        GetBrowserSuite::class,
        SaveBrowserSuite::class,
        LookAtPage::class,
        RunBrowserSuite::class,
        GetBrowserRun::class,
        StopBrowserRun::class,
        SaveBrowserCheck::class,
        EditBrowserCheck::class,
        OpenBrowserPage::class,
        DoBrowserStep::class,
        CloseBrowserPage::class,
        ListWorkflows::class,
        GetWorkflow::class,
        ListWorkflowFolders::class,
        ListWorkflowSteps::class,
        CreateWorkflow::class,
        UpdateWorkflow::class,
        PublishWorkflow::class,
        DeleteWorkflow::class,
        RunWorkflow::class,
        SwitchWorkflow::class,
        ListWorkflowRuns::class,
        GetWorkflowRun::class,
        SearchPlaces::class,
        ListPlaces::class,
        SavePlace::class,
        DeletePlace::class,
        ListDrive::class,
        GetFile::class,
        UploadFile::class,
        RequestUpload::class,
        UpdateFile::class,
        DeleteFile::class,
    ];

    /**
     * @return list<UserTool>
     */
    public static function tools(bool $global): array
    {
        return array_map(fn (string $tool) => new $tool(global: $global), self::TOOLS);
    }
}

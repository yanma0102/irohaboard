<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\GroupsTable;
use Cake\TestSuite\TestCase;

/**
 * GroupsTable のテスト
 */
class GroupsTableTest extends TestCase
{
    protected GroupsTable $Groups;

    public function setUp(): void
    {
        parent::setUp();
        $this->Groups = $this->getTableLocator()->get('Groups');
        $this->Groups->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersGroups')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->Groups);
        parent::tearDown();
    }

    private function saveUser(string $username): int
    {
        $Users = $this->getTableLocator()->get('Users');
        $user = $Users->save($Users->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'ユーザー ' . $username,
            'role' => 'user',
        ]));
        $this->assertNotFalse($user);

        return (int)$user->id;
    }

    private function saveGroup(string $title): int
    {
        $entity = $this->Groups->newEntity(['title' => $title]);
        $result = $this->Groups->save($entity);
        $this->assertNotFalse($result);

        return (int)$result->id;
    }

    public function testGetUserIdByGroupID(): void
    {
        $groupId = $this->saveGroup('グループ1');

        $this->assertSame([], $this->Groups->getUserIdByGroupID($groupId), '所属ユーザがいない場合 空配列');

        $userA = $this->saveUser('grpuser01');
        $userB = $this->saveUser('grpuser02');

        $UsersGroups = $this->getTableLocator()->get('UsersGroups');
        $UsersGroups->save($UsersGroups->newEntity([
            'group_id' => $groupId,
            'user_id' => $userA,
        ]));
        $UsersGroups->save($UsersGroups->newEntity([
            'group_id' => $groupId,
            'user_id' => $userB,
        ]));

        $result = $this->Groups->getUserIdByGroupID($groupId);
        $this->assertSame([$userA, $userB], array_values($result), '所属ユーザのIDリストが返る');

        // 他グループのユーザは含まれない
        $otherGroupId = $this->saveGroup('グループ2');
        $userC = $this->saveUser('grpuser03');
        $UsersGroups->save($UsersGroups->newEntity([
            'group_id' => $otherGroupId,
            'user_id' => $userC,
        ]));

        $result = $this->Groups->getUserIdByGroupID($groupId);
        $this->assertSame([$userA, $userB], array_values($result), '他グループのユーザは含まれない');
    }

    public function testFindOrderedSortsByTitle(): void
    {
        $this->Groups->save($this->Groups->newEntity(['title' => 'グループB']));
        $this->Groups->save($this->Groups->newEntity(['title' => 'グループA']));

        $titles = $this->Groups->find('ordered')->all()->extract('title')->toList();
        $this->assertSame(['グループA', 'グループB'], $titles);
    }
}
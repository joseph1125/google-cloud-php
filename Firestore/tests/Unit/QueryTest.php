<?php
/**
 * Copyright 2017 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Cloud\Firestore\Tests\Unit;

use Google\Cloud\Core\Testing\ArrayHasSameValuesToken;
use Google\Cloud\Core\Testing\TestHelpers;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\Connection\ConnectionInterface;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Query;
use Google\Cloud\Firestore\V1beta1\StructuredQuery\CompositeFilter\Operator;
use Google\Cloud\Firestore\V1beta1\StructuredQuery\Direction;
use Google\Cloud\Firestore\V1beta1\StructuredQuery\FieldFilter\Operator as FieldFilterOperator;
use Google\Cloud\Firestore\ValueMapper;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;

/**
 * @group firestore
 * @group firestore-query
 */
class QueryTest extends TestCase
{
    const PROJECT = 'example_project';
    const DATABASE = '(default)';
    const PARENT = 'projects/example_project/databases/(default)/documents';

    private $queryObj = [
        'from' => [
            ['collectionId' => 'foo']
        ]
    ];
    private $connection;
    private $query;

    public function setUp()
    {
        $this->connection = $this->prophesize(ConnectionInterface::class);
        $this->query = TestHelpers::stub(Query::class, [
            $this->connection->reveal(),
            new ValueMapper($this->connection->reveal(), false),
            self::PARENT,
            $this->queryObj
        ], ['connection', 'query', 'transaction']);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructMissingFrom()
    {
        new Query(
            $this->connection->reveal(),
            new ValueMapper($this->connection->reveal(), false),
            self::PARENT,
            []
        );
    }

    public function testDocuments()
    {
        $name = self::PARENT .'/foo';

        $this->connection->runQuery(Argument::any())
            ->shouldBeCalled()
            ->willReturn(new \ArrayIterator([
                [
                    'document' => [
                        'name' => $name,
                        'fields' => [
                            'hello' => [
                                'stringValue' => 'world'
                            ]
                        ]
                    ],
                    'readTime' => (new \DateTime)->format(Timestamp::FORMAT)
                ],
                []
            ]));

        $this->query->___setProperty('connection', $this->connection->reveal());

        $res = $this->query->documents(['maxRetries' => 0]);
        $this->assertContainsOnlyInstancesOf(DocumentSnapshot::class, $res);
        $this->assertCount(1, $res->rows());

        $current = $res->rows()[0];
        $this->assertEquals($name, $current->name());
        $this->assertEquals('world', $current['hello']);
    }

    public function testDocumentsMetadata()
    {
        $name = self::PARENT .'/foo';

        $ts = (new \DateTime)->format(Timestamp::FORMAT);
        $this->connection->runQuery(Argument::any())
            ->shouldBeCalled()
            ->willReturn(new \ArrayIterator([
                [
                    'document' => [
                        'name' => $name,
                        'fields' => [
                            'hello' => [
                                'stringValue' => 'world'
                            ]
                        ],
                        'createTime' => $ts,
                        'updateTime' => $ts
                    ],
                    'readTime' => $ts
                ],
                []
            ]));

        $this->query->___setProperty('connection', $this->connection->reveal());

        $res = $this->query->documents()->rows()[0];
        $this->assertInstanceOf(Timestamp::class, $res->createTime());
        $this->assertInstanceOf(Timestamp::class, $res->updateTime());
        $this->assertInstanceOf(Timestamp::class, $res->readTime());
    }

    public function testSelect()
    {
        $paths = [
            'users.john',
            'users.dave'
        ];

        $this->runAndAssert(function (Query $q) use ($paths) {
            return $q->select($paths);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'select' => [
                    'fields' => [
                        [ 'fieldPath' => 'users.john' ],
                        [ 'fieldPath' => 'users.dave' ],
                    ]
                ]
            ]
        ]);
    }

    public function testSelectOverride()
    {
        $paths = [
            'users.john',
            'users.dave'
        ];

        $this->runAndAssert(function (Query $q) use ($paths) {
            $res = $q->select($paths);
            $res = $res->select(['users.dan']);
            return $res;
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'select' => [
                    'fields' => [
                        [ 'fieldPath' => 'users.dan' ],
                    ]
                ]
            ]
        ]);
    }

    public function testSelectName()
    {
        $this->runAndAssert(function (Query $q) {
            return $q->select([]);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'select' => [
                    'fields' => [
                        [ 'fieldPath' => '__name__' ]
                    ]
                ]
            ]
        ]);
    }

    public function testWhere()
    {
        $this->runAndAssert(function (Query $q) {
            $res = $q->where('user.name', '=', 'John');
            $res = $res->where('user.age', '=', 30);
            $res = $res->where('user.coolness', '=', null);
            $res = $res->where('user.numberOfFriends', '=', NAN);

            return $res;
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'where' => [
                    'compositeFilter' => [
                        'op' => Operator::PBAND,
                        'filters' => [
                            [
                                'fieldFilter' => [
                                    'field' => [
                                        'fieldPath' => 'user.name'
                                    ],
                                    'op' => FieldFilterOperator::EQUAL,
                                    'value' => [
                                        'stringValue' => 'John'
                                    ]
                                ]
                            ], [
                                'fieldFilter' => [
                                    'field' => [
                                        'fieldPath' => 'user.age'
                                    ],
                                    'op' => FieldFilterOperator::EQUAL,
                                    'value' => [
                                        'integerValue' => '30'
                                    ]
                                ]
                            ],
                            [
                                'unaryFilter' => [
                                    'field' => [
                                        'fieldPath' => 'user.coolness'
                                    ],
                                    'op' => Query::OP_NULL
                                ]
                            ], [
                                'unaryFilter' => [
                                    'field' => [
                                        'fieldPath' => 'user.numberOfFriends'
                                    ],
                                    'op' => Query::OP_NAN
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    }

    /**
     * @dataProvider invalidUnaryComparisonOperators
     * @expectedException InvalidArgumentException
     */
    public function testWhereUnaryInvalidComparisonOperator($operator)
    {
        $this->query->where('foo', $operator, null);
    }

    public function invalidUnaryComparisonOperators()
    {
        return [
            [Query::OP_LESS_THAN],
            [Query::OP_LESS_THAN_OR_EQUAL],
            [Query::OP_GREATER_THAN],
            [Query::OP_GREATER_THAN_OR_EQUAL],
            ['<'],
            ['<='],
            ['>'],
            ['>='],
        ];
    }

    /**
     * @dataProvider whereOperators
     */
    public function testWhereOperators($operator)
    {
        $this->query->where('foo', $operator, 'bar');
    }

    public function whereOperators()
    {
        return [
            [Query::OP_LESS_THAN],
            [Query::OP_LESS_THAN_OR_EQUAL],
            [Query::OP_GREATER_THAN],
            [Query::OP_GREATER_THAN_OR_EQUAL],
            [Query::OP_EQUAL],
            [Query::OP_ARRAY_CONTAINS],
            ['<'],
            ['<='],
            ['>'],
            ['>='],
            ['='],
            ['array-contains'],
        ];
    }

    /**
     * @expectedException InvalidArgumentException
     * @dataProvider sentinels
     */
    public function testWhereInvalidSentinelValue($sentinel)
    {
        $this->query->where('foo', '=', $sentinel);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testWhereInvalidOperator()
    {
        $this->query->where('foo', 'hello', 'bar');
    }

    public function testOrderBy()
    {
        $this->runAndAssert(function (Query $q) {
            $res = $q->orderBy('user.name', 'DESC');
            $res = $res->orderBy('user.age', 'ASC');

            return $res;
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => ['fieldPath' => 'user.name'],
                        'direction' => Direction::DESCENDING
                    ], [
                        'field' => ['fieldPath' => 'user.age'],
                        'direction' => Direction::ASCENDING
                    ]
                ]
            ]
        ]);
    }

    /**
     * @expectedException InvalidArgumentException
     * @dataProvider cursors
     */
    public function testOrderByAfterCursor($cursor)
    {
        $this->query->orderBy('foo')->$cursor(['bar'])->orderBy('world');
    }

    public function cursors()
    {
        return [
            ['startAt'], ['startAfter'], ['endBefore'], ['endAt']
        ];
    }

    /**
     * @dataProvider orderOperators
     */
    public function testorderOperators($operator)
    {
        $this->query->orderBy('foo', $operator);
    }

    public function orderOperators()
    {
        return [
            [Query::DIR_ASCENDING],
            [Query::DIR_DESCENDING],
            ['ASC'],
            ['DESC'],
            ['asc'],
            ['desc'],
            ['ASCENDING'],
            ['DESCENDING'],
            ['ascending'],
            ['descending'],
        ];
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testOrderByInvalidOperator()
    {
        $this->query->orderBy('foo', 'hello');
    }

    public function testLimit()
    {
        $limit = 50;

        $this->runAndAssert(function (Query $q) use ($limit) {
            return $q->limit($limit);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'limit' => ['value' => $limit]
            ]
        ]);
    }

    public function testOffset()
    {
        $offset = 50;

        $this->runAndAssert(function (Query $q) use ($offset) {
            return $q->offset($offset);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'offset' => $offset
            ]
        ]);
    }

    public function testStartAt()
    {
        $this->runAndAssert(function (Query $q) {
            return $q->orderBy('name', Query::DIR_DESCENDING)->startAt(['john']);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => 'name'
                        ],
                        'direction' => Query::DIR_DESCENDING
                    ]
                ],
                'startAt' => [
                    'before' => true,
                    'values' => [
                        [
                            'stringValue' => 'john'
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function testStartAfter()
    {
        $this->runAndAssert(function (Query $q) {
            return $q->orderBy('name', Query::DIR_DESCENDING)->startAfter(['john']);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => 'name'
                        ],
                        'direction' => Query::DIR_DESCENDING
                    ]
                ],
                'startAt' => [
                    'before' => false,
                    'values' => [
                        [
                            'stringValue' => 'john'
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function testEndBefore()
    {
        $this->runAndAssert(function (Query $q) {
            return $q->orderBy('name', Query::DIR_DESCENDING)->endBefore(['john']);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => 'name'
                        ],
                        'direction' => Query::DIR_DESCENDING
                    ]
                ],
                'endAt' => [
                    'before' => true,
                    'values' => [
                        [
                            'stringValue' => 'john'
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function testEndAt()
    {
        $this->runAndAssert(function (Query $q) {
            return $q->orderBy('name', Query::DIR_DESCENDING)->endAt(['john']);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => 'name'
                        ],
                        'direction' => Query::DIR_DESCENDING
                    ]
                ],
                'endAt' => [
                    'before' => false,
                    'values' => [
                        [
                            'stringValue' => 'john'
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function testBuildPositionWithDocumentId()
    {
        $this->runAndAssert(function (Query $q) {
            return $q->orderBy(Query::DOCUMENT_ID, Query::DIR_DESCENDING)->endAt(['john']);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => Query::DOCUMENT_ID
                        ],
                        'direction' => Query::DIR_DESCENDING
                    ]
                ],
                'endAt' => [
                    'before' => false,
                    'values' => [
                        [
                            'referenceValue' => self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john'
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function testBuildPositionWithDocumentReference()
    {
        $c = $this->prophesize(CollectionReference::class);
        $c->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId']);

        $ref = $this->prophesize(DocumentReference::class);
        $ref->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john');
        $ref->parent()->willReturn($c->reveal());

        $this->runAndAssert(function (Query $q) use ($ref) {
            return $q->orderBy(Query::DOCUMENT_ID, Query::DIR_DESCENDING)->endAt([$ref->reveal()]);
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => Query::DOCUMENT_ID
                        ],
                        'direction' => Query::DIR_DESCENDING
                    ]
                ],
                'endAt' => [
                    'before' => false,
                    'values' => [
                        [
                            'referenceValue' => self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john'
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function testPositionWithDocumentSnapshot()
    {
        $c = $this->prophesize(CollectionReference::class);
        $c->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId']);

        $ref = $this->prophesize(DocumentReference::class);
        $ref->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john');
        $ref->parent()->willReturn($c->reveal());

        $snapshot = $this->prophesize(DocumentSnapshot::class);
        $snapshot->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john');
        $snapshot->reference()->willReturn($ref->reveal());

        $this->runAndAssert(function (Query $q) use ($snapshot) {
            return $this->query->startAt($snapshot->reveal());
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => Query::DOCUMENT_ID
                        ],
                        'direction' => Query::DIR_ASCENDING
                    ]
                ],
                'startAt' => [
                    'before' => true,
                    'values' => [
                        [
                            'referenceValue' => self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john'
                        ]
                    ]
                ]
            ]
        ]);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSnapshotInFieldValue()
    {
        $snapshot = $this->prophesize(DocumentSnapshot::class);
        $this->query->startAt([$snapshot->reveal()]);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidFieldValues()
    {
        $this->query->startAt('foo');
    }

    public function testPositionSnapshotOrderBy()
    {
        $c = $this->prophesize(CollectionReference::class);
        $c->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId']);

        $ref = $this->prophesize(DocumentReference::class);
        $ref->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john');
        $ref->parent()->willReturn($c->reveal());

        $snapshot = $this->prophesize(DocumentSnapshot::class);
        $snapshot->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john');
        $snapshot->reference()->willReturn($ref->reveal());
        $snapshot->get('a')->willReturn('b');
        $snapshot->get('c')->willReturn('d');

        $this->runAndAssert(function (Query $q) use ($snapshot) {
            $query = $this->query->orderBy('a')->orderBy('c');
            return $query->startAt($snapshot->reveal());
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => 'a'
                        ],
                        'direction' => Query::DIR_ASCENDING
                    ], [
                        'field' => [
                            'fieldPath' => 'c'
                        ],
                        'direction' => Query::DIR_ASCENDING
                    ], [
                        'field' => [
                            'fieldPath' => Query::DOCUMENT_ID
                        ],
                        'direction' => Query::DIR_ASCENDING
                    ]
                ],
                'startAt' => [
                    'before' => true,
                    'values' => [
                        ['stringValue' => 'b'],
                        ['stringValue' => 'd'],
                        [
                            'referenceValue' => self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john'
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function testPositionInequalityFilter()
    {
        $c = $this->prophesize(CollectionReference::class);
        $c->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId']);

        $ref = $this->prophesize(DocumentReference::class);
        $ref->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john');
        $ref->parent()->willReturn($c->reveal());

        $snapshot = $this->prophesize(DocumentSnapshot::class);
        $snapshot->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john');
        $snapshot->reference()->willReturn($ref->reveal());
        $snapshot->get('foo')->willReturn('bar');

        $this->runAndAssert(function (Query $q) use ($snapshot) {
            $query = $this->query->where('foo', '>', 'bar');
            return $query->startAt($snapshot->reveal());
        }, [
            'parent' => self::PARENT,
            'structuredQuery' => [
                'from' => $this->queryFrom(),
                'orderBy' => [
                    [
                        'field' => [
                            'fieldPath' => 'foo'
                        ],
                        'direction' => Query::DIR_ASCENDING
                    ], [
                        'field' => [
                            'fieldPath' => Query::DOCUMENT_ID
                        ],
                        'direction' => Query::DIR_ASCENDING
                    ]
                ],
                'startAt' => [
                    'before' => true,
                    'values' => [
                        [
                            'stringValue' => 'bar'
                        ], [
                            'referenceValue' => self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john'
                        ]
                    ]
                ],
                'where' => [
                    'fieldFilter' => [
                        'field' => [
                            'fieldPath' => 'foo'
                        ],
                        'op' => 3,
                        'value' => [
                            'stringValue' => 'bar'
                        ]
                    ]
                ]
            ]
        ]);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testBuildPositionTooManyCursorValues()
    {
        $this->query->orderBy('foo')->endAt(['a','b']);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testBuildPositionOutOfBounds()
    {
        $ref = $this->prophesize(DocumentReference::class);
        $ref->name()->willReturn(self::PARENT .'/whatev/john');

        $col = $this->prophesize(CollectionReference::class);
        $col->name()->willReturn(self::PARENT .'/whatev/john');
        $ref->parent()->willReturn($col->reveal());

        $this->query->orderBy(Query::DOCUMENT_ID)->startAt([$ref->reveal()]);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testBuildPositionInvalidCursorType()
    {
        $this->query->orderBy(Query::DOCUMENT_ID)->startAt([10]);
    }

    /**
     * @expectedException InvalidArgumentException
     * @dataProvider sentinels
     */
    public function testBuildPositionInvalidSentinelValue($sentinel)
    {
        $this->query->orderBy(Query::DOCUMENT_ID)->startAt([$sentinel]);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testBuildPositionNestedChild()
    {
        $c = $this->prophesize(CollectionReference::class);
        $c->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john');

        $ref = $this->prophesize(DocumentReference::class);
        $ref->name()->willReturn(self::PARENT .'/'. $this->queryFrom()[0]['collectionId'] .'/john/bar');
        $ref->parent()->willReturn($c->reveal());

        $this->query->orderBy(Query::DOCUMENT_ID)->startAt([$ref->reveal()]);
    }

    private function runAndAssert(callable $filters, $assertion)
    {
        if (is_array($assertion)) {
            if (isset($assertion['structuredQuery'])) {
                $assertion['structuredQuery'] = array_merge([
                    'orderBy' => [],
                    'offset' => 0
                ], $assertion['structuredQuery']);
            }

            $this->connection->runQuery(
                new ArrayHasSameValuesToken($assertion + ['retries' => 0])
            )->shouldBeCalledTimes(1)->willReturn(new \ArrayIterator([
                []
            ]));
        } elseif (is_callable($assertion)) {
            $this->connection->runQuery(Argument::that($assertion))
                ->shouldBeCalledTimes(1);
        }

        $immutable = clone $this->query;
        $immutable->___setProperty('connection', $this->connection->reveal());
        $query = $filters($immutable);

        $this->assertInstanceOf(Query::class, $query);
        $this->assertNotEquals($immutable, $query);

        iterator_to_array($query->documents(['maxRetries' => 0]));
    }

    private function queryFrom()
    {
        return $this->queryObj['from'];
    }

    public function sentinels()
    {
        return [
            [FieldValue::deleteField()],
            [FieldValue::serverTimestamp()],
            [FieldValue::arrayUnion([])],
            [FieldValue::arrayRemove([])]
        ];
    }
}

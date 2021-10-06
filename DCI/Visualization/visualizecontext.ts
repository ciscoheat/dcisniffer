enum Clicks {
    Single = 1,
    Double,
    Triple
}

class VisualizeContext {
    constructor(nodes: vis.Node[], edges: vis.Edge[], container: HTMLElement) {
        //this.roles = new Set(nodes.map(node => node.group))

        const nodeSet = this.nodes = new vis.DataSet(nodes.map((node, index, arr) => {
            const angle = 2 * Math.PI * (index / arr.length + 0.75);
            const radius = 225 + arr.length * 10

            const output = Object.assign({}, node)
            output.x = radius * Math.cos(angle);
            output.y = radius * Math.sin(angle);
            return output;
        }))

        const edgeSet = this.edges = new vis.DataSet(
            edges.map(e => Object.assign({}, e))
        )

        // Set node border and size based on connected edges        
        nodeSet.update(nodeSet.get()
        .map(node => {
            const nodeEdgesFrom = edgeSet.get({filter: e => e.from == node.id})
            const nodeEdgesTo = edgeSet.get({filter: e => e.to == node.id})

            const uniqueEdges = (edges) => new Set(edges.map(e => e.from + e.to))
            const borderWidth = uniqueEdges(nodeEdgesTo).size * 1.5

            if(nodeEdgesFrom.length == 0)
                this._getterNodes.push(node.id)

            return {
                id: node.id,
                shape: node.group != '__CONTEXT' 
                    ? (nodeEdgesFrom.length > 0 ? 'dot' : 'diamond')
                    : null,
                borderWidth: borderWidth,
                borderWidthSelected: borderWidth,
                size: 20 + nodeEdgesFrom.length * 3
            }
        }))

        const options = {
            physics: false,
            nodes: {
                shape: 'dot',
                font: {
                    size: 16
                }
            },
            edges: {
                arrows: 'to',
                selectionWidth: width => Math.max(3, width * 1.5)
            },
            groups: {
                '__CONTEXT': {
                    shape: 'box',
                    shapeProperties: {
                        borderRadius: 1
                    },
                    color: '#bbb',
                    font: {
                        size: 18
                    }
                }
            }
        }

        this.network = new vis.Network(container, {
            nodes: nodeSet,
            edges: edgeSet
        }, options as any)

        this.tools = {
            interactions: false
        }

        this.clicks = [0, 0]
        
    } // end constructor

    private _getterNodes : vis.IdType[] = []

    ///// System operations /////////////////////////////////////////

    start() {        
        const network = this.network as vis.Network
        network.on("click", params => this.network_addToSelection(params))
        this.edges_displayAll()
    }

    setInteractions(state: boolean) {
        this.tools_setInteractions(state)
    }

    ///// Roles /////////////////////////////////////////////////////

    ///// edges ///////////////////////////////////////////

    private edges: {
        get()
        get(options?: vis.DataSelectionOptions<vis.Edge>): vis.Edge[];
        get(ids: vis.IdType[], options?: vis.DataSelectionOptions<vis.Edge>): vis.Edge[];
        update(data: vis.Edge | vis.Edge[], senderId?: vis.IdType): vis.IdType[];
    }

    protected edges_displayAll() : void {
        this.edges_display(null)
    }

    protected edges_hideAll() : void {
        this.edges_display(null, false)
    }

    protected edges_display(edgeIds?: vis.IdType[], display = true) : void {
        if(edgeIds == null)
            edgeIds = this.edges.get().map(e => e.id)

        let updates : {id: vis.IdType, hidden: boolean}[] = []
        
        if(this.tools.interactions) {
            updates = this.edges.get(edgeIds)
            .map(e => {
                const filterInteraction = display
                return {
                    id: e.id,
                    hidden: filterInteraction ? this._getterNodes.includes(e.to) : !display
                }
            })
        } else {
            updates = edgeIds.map(id => ({
                id: id,
                hidden: !display
            }))
        }

        this.edges.update(updates)
    }

    ///// clicks //////////////////////////////////////////

    private clicks: [number, number];

    protected clicks_track() : Clicks {
        const now = Date.now()

        let nrClicks = Clicks.Single
        if(now - this.clicks[1] < 600) nrClicks = Clicks.Triple
        else if(now - this.clicks[0] < 500) nrClicks = Clicks.Double

        this.clicks.unshift(now)
        this.clicks.pop()

        return nrClicks
    }

    ///// selected ////////////////////////////////////////

    private _selected: vis.Node | null

    ///// network /////////////////////////////////////////

    private network: {
        getNodeAt(pos: vis.Position) : vis.IdType
        getEdgeAt(pos: vis.Position) : vis.IdType
        getConnectedNodes(nodeOrEdgeId: vis.IdType, direction?: vis.DirectionType): vis.IdType[] | Array<{ fromId: vis.IdType, toId: vis.IdType }>;
        getConnectedEdges(nodeId: vis.IdType): vis.IdType[];
    }

    protected network_addToSelection(params: { pointer: { DOM: vis.Position; }; }) : void {
        const nodeId = this.network.getNodeAt(params.pointer.DOM)
        const edgeId = this.network.getEdgeAt(params.pointer.DOM)

        if(!nodeId && !edgeId) {
            this.edges_displayAll()
            return
        }

        this._selected = nodeId ? this.nodes_get(nodeId) : null

        // Hide all edges before displaying the selected ones
        this.edges_hideAll()

        const clicks = this.clicks_track()

        if(nodeId && clicks == Clicks.Triple) {
            this.edges_display(this.nodes_uniPathFrom(nodeId))
        } else {
            this.nodes_displayEdgesFor(
                nodeId 
                    ? [nodeId] 
                    : this.network.getConnectedNodes(edgeId) as vis.IdType[],
                clicks == Clicks.Double
            )
        }
    }

    protected network_connectedEdges(nodeId: vis.IdType) : vis.IdType[] {
        return this.network.getConnectedEdges(nodeId)
    }

    ///// nodes ///////////////////////////////////////////

    private nodes: {
        get(id: vis.IdType, options?: vis.DataSelectionOptions<vis.Node>): vis.Node | null;
        get(ids: vis.IdType[], options?: vis.DataSelectionOptions<vis.Node>): vis.Node[];
        get(options?: vis.DataSelectionOptions<vis.Node>): vis.Node[];
        //update(data: vis.Node | vis.Node[], senderId?: vis.IdType): vis.IdType[];
    }

    protected nodes_get(id: vis.IdType) : vis.Node {
        return this.nodes.get(id)
    }

    protected nodes_displayEdgesFor(nodeIdList: vis.IdType[], onlyExactNodes: boolean) : void {
        const nodes = this.nodes.get(nodeIdList)

        const filter = onlyExactNodes
            ? n => nodes.some(n2 => n2.id == n.id)
            : n => nodes.some(selected => selected.group == n.group)

        this.nodes__filterAndDisplayEdges(filter)
    }

    private nodes__filterAndDisplayEdges(filter: (n: vis.Node) => boolean, visible = true) : void {
        const edges = this.nodes
        .get({ filter: filter })
        .map(n => n.id)
        .flatMap(id => this.network_connectedEdges(id))

        this.edges_display(edges, visible)
    }

    protected nodes_uniPathFrom(nodeId: vis.IdType, visitedIds: vis.IdType[] = []) : vis.IdType[] {
        visitedIds.push(nodeId)

        const fromEdges = (nodeId) => this.edges
        .get(this.network_connectedEdges(nodeId))
        .filter(e => e.from == nodeId)

        const allEdges = fromEdges(nodeId)

        const addEdges = allEdges
        .filter(e => fromEdges(e.to).length > 0)

        return addEdges.map(e => e.id).concat(
            allEdges
            .filter(e => !visitedIds.includes(e.to))
            .flatMap(e => this.nodes_uniPathFrom(e.to, visitedIds))
        )
    }

    ///// tools ///////////////////////////////////////////

    private tools: {
        interactions: boolean
    }

    protected tools_setInteractions(state: boolean) {
        this.tools.interactions = state
        this.edges_displayAll()
    }
}

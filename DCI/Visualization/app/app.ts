import m from 'mithril'
import { VisualizeContext, VisualizeContextState } from './visualizecontext'

class App implements m.ClassComponent<VisualizeContextState> {
    state: VisualizeContextState
    visualizer: VisualizeContext

    constructor(state) {
        this.state = state
    }

    update(changeState: () => void) {
        changeState()
        if(this.visualizer)
            this.visualizer.redraw()
    }

    view() { 
        return m('#app', [
            m('#toolbar', [
                m('input[type=checkbox]', {
                    checked: this.state.onlyInteractions,
                    onclick: e => this.update(() => 
                        this.state.onlyInteractions = e.target.checked
                    )
                }),
                "Display interactions only"
            ]),
            m('#mynetwork', {
                oncreate: async (vnode : m.VnodeDOM) => {
                    const json = await fetch("RoleConventionsSniff.json")
                        .then(response => response.json())

                    this.visualizer = new VisualizeContext(
                        json.nodes, 
                        json.edges, 
                        vnode.dom as HTMLElement
                    )
        
                    this.visualizer.start()
                }
            })
        ])
    }
}

m.mount(document.body, App)

/*
        fetch("RoleConventionsSniff.json")
        .then(response => response.json())
        .then(json => {
            const visualize = new VisualizeContext(
                json.nodes, 
                json.edges, 
                document.querySelector('#mynetwork'),
                document.querySelector('#toolbar')
            )

            visualize.start()
        })
*/